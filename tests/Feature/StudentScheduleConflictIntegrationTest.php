<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\CuentaPorCobrar;
use App\Models\CursoAbierto;
use App\Models\Finance\LineaPagoModulo;
use App\Models\Horario;
use App\Models\HorarioDia;
use App\Models\HorarioTaller;
use App\Models\InscripcionTaller;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\Taller;
use App\Models\TransaccionIngreso;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentScheduleConflictIntegrationTest extends TestCase
{
    private function student(): Persona
    {
        return Persona::create([
            'tipo' => 'estudiante',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Estudiante Integración',
            'apellidos' => Str::uuid()->toString(),
            'correo' => Str::uuid().'@example.test',
            'es_activo' => true,
        ]);
    }

    private function course(bool $custom = false, string $start = '18:00:00', string $end = '20:00:00', int $day = 1, ?array $dates = null): array
    {
        $dates ??= [now()->addDays(20)->toDateString(), now()->addDays(80)->toDateString()];
        $catalog = CatalogoCurso::create([
            'categoria' => $custom ? 'personalizado' : 'regular',
            'nombre' => 'Catálogo '.Str::uuid(),
            'creditos' => 3,
            'horas_totales' => 40,
            'es_activo' => true,
        ]);
        $course = CursoAbierto::create([
            'catalogo_curso_id' => $catalog->id,
            'es_personalizado' => $custom,
            'nombre_instancia' => ($custom ? 'Personalizado ' : 'Curso ').Str::uuid(),
            'modalidad' => 'virtual',
            'fecha_inicio' => $dates[0],
            'fecha_fin' => $dates[1],
            'precio_base' => $custom ? 300 : 100,
            'capacidad_maxima' => 20,
            'es_activo' => true,
        ]);
        $schedule = Horario::create([
            'nombre_referencial' => 'Horario integración',
            'dia_semana' => '{'.$day.'}',
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'es_activo' => true,
        ]);
        HorarioDia::create(['horario_id' => $schedule->id, 'dia_semana' => $day]);
        $course->update(['horario_id' => $schedule->id]);

        $module = Modulo::create([
            'curso_abierto_id' => $course->id,
            'nombre_modulo' => 'Módulo integración',
            'numero_orden' => 1,
            'fecha_inicio' => $dates[0],
            'fecha_fin' => $dates[1],
            'precio_base' => 100,
        ]);

        return [$course, $module];
    }

    private function workshop(string $start = '18:00:00', string $end = '20:00:00', int $day = 1, ?array $dates = null, int $sessions = 1): Taller
    {
        $dates ??= [now()->addDays(20)->toDateString(), now()->addDays(80)->toDateString()];
        $taller = Taller::create([
            'nombre' => 'Taller '.Str::uuid(),
            'modalidad' => 'presencial',
            'fecha' => $dates[0],
            'fecha_fin' => $dates[1],
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'capacidad_maxima' => 20,
            'precio' => 100,
            'estado' => 'confirmado',
        ]);
        for ($i = 0; $i < $sessions; $i++) {
            HorarioTaller::create([
                'taller_id' => $taller->id,
                'dia_semana' => $i === 0 ? $day : 3,
                'hora_inicio' => $i === 0 ? $start : '08:00:00',
                'hora_fin' => $i === 0 ? $end : '09:00:00',
                'capacidad' => 20,
            ]);
        }
        return $taller;
    }

    private function enrollCourse(Persona $student, CursoAbierto $course, Modulo $module): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
            'estudiante_id' => $student->id,
            'curso_abierto_id' => $course->id,
            'pagos' => [['modulo_id' => $module->id, 'monto' => 100]],
            'metodo_pago' => 'efectivo',
        ]);
    }

    private function enrollCustom(Persona $student, CursoAbierto $course, float $amount = 100): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
            'estudiante_id' => $student->id,
            'curso_abierto_id' => $course->id,
            'pago_inicial' => $amount,
            'metodo_pago' => 'efectivo',
        ]);
    }

    private function enrollWorkshop(Persona $student, Taller $workshop): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/academic/inscripciones-talleres/inscribir-desde-perfil', [
            'estudiante_id' => $student->id,
            'taller_id' => $workshop->id,
            'monto_pagado' => 100,
            'metodo_pago' => 'efectivo',
        ]);
    }

    private function assertConflict(\Illuminate\Testing\TestResponse $response, string $type): void
    {
        $response->assertStatus(409)
            ->assertJsonStructure(['mensaje', 'conflictos' => [['tipo', 'actividad_id', 'nombre', 'fecha_inicio', 'fecha_fin', 'dia_semana', 'hora_inicio', 'hora_fin']]]);
        $this->assertNotEmpty($response->json('conflictos'));
        $this->assertSame($type, $response->json('conflictos.0.tipo'));
    }

    private function assertNoCourseArtifacts(CursoAbierto $course): void
    {
        $matriculaIds = Matricula::where('curso_abierto_id', $course->id)->pluck('id');
        $accountIds = CuentaPorCobrar::whereIn('matricula_id', $matriculaIds)->pluck('id');
        $this->assertCount(0, $matriculaIds);
        $this->assertCount(0, $accountIds);
        $this->assertSame(0, LineaPagoModulo::whereIn('matricula_id', $matriculaIds)->count());
        $this->assertSame(0, TransaccionIngreso::whereIn('cuenta_cobrar_id', $accountIds)->count());
    }

    private function assertNoWorkshopArtifacts(Taller $workshop, Persona $student): void
    {
        $inscripcionIds = InscripcionTaller::where('taller_id', $workshop->id)->where('persona_id', $student->id)->pluck('id');
        $accountIds = CuentaPorCobrar::whereIn('inscripcion_taller_id', $inscripcionIds)->pluck('id');
        $this->assertCount(0, $inscripcionIds);
        $this->assertCount(0, $accountIds);
        $this->assertSame(0, TransaccionIngreso::whereIn('cuenta_cobrar_id', $accountIds)->count());
    }

    public function test_course_to_course_conflict_rolls_back_destination_finances(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        [$a, $moduleA] = $this->course();
        [$b, $moduleB] = $this->course(false, '19:00:00', '21:00:00');

        $this->enrollCourse($student, $a, $moduleA)->assertCreated();
        $before = [Matricula::where('curso_abierto_id', $b->id)->count(), CuentaPorCobrar::whereHas('matricula', fn ($q) => $q->where('curso_abierto_id', $b->id))->count(), LineaPagoModulo::whereHas('matricula', fn ($q) => $q->where('curso_abierto_id', $b->id))->count()];
        $this->assertConflict($this->enrollCourse($student, $b, $moduleB), 'curso');
        $this->assertSame($before, [Matricula::where('curso_abierto_id', $b->id)->count(), CuentaPorCobrar::whereHas('matricula', fn ($q) => $q->where('curso_abierto_id', $b->id))->count(), LineaPagoModulo::whereHas('matricula', fn ($q) => $q->where('curso_abierto_id', $b->id))->count()]);
    }

    public function test_all_course_variants_detect_conflicts_both_directions(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        [$normal, $normalModule] = $this->course();
        [$custom, $customModule] = $this->course(true, '19:00:00', '21:00:00');
        $this->enrollCourse($student, $normal, $normalModule)->assertCreated();
        $this->assertConflict($this->enrollCustom($student, $custom), 'curso');
        $this->assertNoCourseArtifacts($custom);

        $student2 = $this->student();
        [$custom2, $customModule2] = $this->course(true);
        [$normal2, $normalModule2] = $this->course(false, '19:00:00', '21:00:00');
        $this->enrollCustom($student2, $custom2)->assertCreated();
        $this->assertConflict($this->enrollCourse($student2, $normal2, $normalModule2), 'curso_personalizado');
        $this->assertNoCourseArtifacts($normal2);

        $student3 = $this->student();
        [$custom3, $customModule3] = $this->course(true);
        [$custom4, $customModule4] = $this->course(true, '19:00:00', '21:00:00');
        $this->enrollCustom($student3, $custom3)->assertCreated();
        $this->assertConflict($this->enrollCustom($student3, $custom4), 'curso_personalizado');
        $this->assertNoCourseArtifacts($custom4);
    }

    public function test_course_and_workshop_conflicts_in_both_directions(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        [$course, $module] = $this->course();
        $workshop = $this->workshop('19:00:00', '21:00:00');
        $this->enrollCourse($student, $course, $module)->assertCreated();
        $this->assertConflict($this->enrollWorkshop($student, $workshop), 'curso');
        $this->assertNoWorkshopArtifacts($workshop, $student);

        $student2 = $this->student();
        $workshop2 = $this->workshop();
        [$course2, $module2] = $this->course(false, '19:00:00', '21:00:00');
        $this->enrollWorkshop($student2, $workshop2)->assertCreated();
        $this->assertConflict($this->enrollCourse($student2, $course2, $module2), 'taller');
        $this->assertNoCourseArtifacts($course2);
    }

    public function test_workshop_to_custom_and_workshop_conflicts_include_multiple_sessions(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        $source = $this->workshop('18:00:00', '20:00:00', 1, null, 2);
        [$custom, $module] = $this->course(true, '19:00:00', '21:00:00');
        $this->enrollWorkshop($student, $source)->assertCreated();
        $this->assertConflict($this->enrollCustom($student, $custom), 'taller');
        $this->assertNoCourseArtifacts($custom);

        $student2 = $this->student();
        $first = $this->workshop();
        $second = $this->workshop('19:00:00', '21:00:00');
        $this->enrollWorkshop($student2, $first)->assertCreated();
        $this->assertConflict($this->enrollWorkshop($student2, $second), 'taller');
        $this->assertNoWorkshopArtifacts($second, $student2);
    }

    public function test_contiguous_dates_and_days_are_allowed(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        [$a, $moduleA] = $this->course(false, '18:00:00', '20:00:00', 1);
        [$contiguous, $moduleB] = $this->course(false, '20:00:00', '22:00:00', 1);
        $this->enrollCourse($student, $a, $moduleA)->assertCreated();
        $this->enrollCourse($student, $contiguous, $moduleB)->assertCreated();

        $student2 = $this->student();
        [$old, $oldModule] = $this->course(false, '18:00:00', '20:00:00', 1);
        [$later, $laterModule] = $this->course(false, '18:00:00', '20:00:00', 1, [now()->addDays(100)->toDateString(), now()->addDays(160)->toDateString()]);
        $this->enrollCourse($student2, $old, $oldModule)->assertCreated();
        $this->enrollCourse($student2, $later, $laterModule)->assertCreated();

        $student3 = $this->student();
        [$monday, $mondayModule] = $this->course(false, '18:00:00', '20:00:00', 1);
        [$tuesday, $tuesdayModule] = $this->course(false, '18:00:00', '20:00:00', 2);
        $this->enrollCourse($student3, $monday, $mondayModule)->assertCreated();
        $this->enrollCourse($student3, $tuesday, $tuesdayModule)->assertCreated();
    }

    public function test_retired_reprobated_and_soft_deleted_courses_are_not_schedule_conflicts(): void
    {
        $this->getAuthToken();
        foreach ([Matricula::ESTADO_RETIRADO, Matricula::ESTADO_REPROBADO] as $state) {
            $student = $this->student();
            [$old, $oldModule] = $this->course();
            [$new, $newModule] = $this->course(false, '19:00:00', '21:00:00');
            Matricula::create(['estudiante_id' => $student->id, 'curso_abierto_id' => $old->id, 'estado' => $state, 'precio_total_legacy' => 0]);
            $this->enrollCourse($student, $new, $newModule)->assertCreated();
        }

        $student = $this->student();
        [$deleted, $deletedModule] = $this->course();
        [$new, $newModule] = $this->course(false, '19:00:00', '21:00:00');
        $old = Matricula::create(['estudiante_id' => $student->id, 'curso_abierto_id' => $deleted->id, 'estado' => Matricula::ESTADO_ACTIVO, 'precio_total_legacy' => 0]);
        $old->delete();
        $this->enrollCourse($student, $new, $newModule)->assertCreated();
    }

    public function test_completed_course_only_conflicts_when_dates_overlap(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        [$historical, $historicalModule] = $this->course(false, '18:00:00', '20:00:00', 1, [now()->subMonths(6)->toDateString(), now()->subMonths(3)->toDateString()]);
        [$current, $currentModule] = $this->course(false, '19:00:00', '21:00:00');
        Matricula::create(['estudiante_id' => $student->id, 'curso_abierto_id' => $historical->id, 'estado' => Matricula::ESTADO_COMPLETADO, 'precio_total_legacy' => 0]);
        $this->enrollCourse($student, $current, $currentModule)->assertCreated();

        $student2 = $this->student();
        [$overlap, $overlapModule] = $this->course(false, '18:00:00', '20:00:00');
        [$destination, $destinationModule] = $this->course(false, '19:00:00', '21:00:00');
        Matricula::create(['estudiante_id' => $student2->id, 'curso_abierto_id' => $overlap->id, 'estado' => Matricula::ESTADO_COMPLETADO, 'precio_total_legacy' => 0]);
        $this->assertConflict($this->enrollCourse($student2, $destination, $destinationModule), 'curso');
    }

    public function test_retired_workshop_is_ignored(): void
    {
        $this->getAuthToken();
        $student = $this->student();
        $retired = $this->workshop();
        InscripcionTaller::create(['taller_id' => $retired->id, 'persona_id' => $student->id, 'nombres' => $student->nombres, 'apellidos' => $student->apellidos, 'estado' => 'retirado', 'monto_pagado' => 0, 'fecha_inscripcion' => now()]);
        [$course, $module] = $this->course(false, '19:00:00', '21:00:00');
        $this->enrollCourse($student, $course, $module)->assertCreated();
    }
}
