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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrencyIntegrationTest extends TestCase
{
    private string $token;
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->getAuthToken();
    }

    private function student(): Persona
    {
        $student = Persona::create([
            'tipo' => 'estudiante',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Concurrente',
            'apellidos' => Str::uuid()->toString(),
            'correo' => Str::uuid().'@example.test',
            'es_activo' => true,
        ]);
        $this->created['people'][] = $student->id;
        return $student;
    }

    private function course(bool $custom = false, int $capacity = 10, string $start = '18:00:00', string $end = '20:00:00', int $day = 1): array
    {
        $catalog = CatalogoCurso::create([
            'categoria' => $custom ? 'personalizado' : 'regular',
            'nombre' => 'Concurrente '.Str::uuid(),
            'creditos' => 3,
            'horas_totales' => 40,
            'es_activo' => true,
        ]);
        $course = CursoAbierto::create([
            'catalogo_curso_id' => $catalog->id,
            'es_personalizado' => $custom,
            'nombre_instancia' => 'Oferta concurrente '.Str::uuid(),
            'modalidad' => 'virtual',
            'fecha_inicio' => now()->addDays(20)->toDateString(),
            'fecha_fin' => now()->addDays(80)->toDateString(),
            'precio_base' => $custom ? 300 : 100,
            'capacidad_maxima' => $capacity,
            'es_activo' => true,
        ]);
        $schedule = Horario::create([
            'nombre_referencial' => 'Horario concurrente',
            'dia_semana' => '{'.$day.'}',
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'es_activo' => true,
        ]);
        HorarioDia::create(['horario_id' => $schedule->id, 'dia_semana' => $day]);
        $course->update(['horario_id' => $schedule->id]);
        $module = Modulo::create([
            'curso_abierto_id' => $course->id,
            'nombre_modulo' => 'Módulo concurrente',
            'numero_orden' => 1,
            'fecha_inicio' => now()->addDays(20)->toDateString(),
            'fecha_fin' => now()->addDays(80)->toDateString(),
            'precio_base' => 100,
        ]);
        $this->created['courses'][] = $course->id;
        $this->created['catalogs'][] = $catalog->id;
        $this->created['schedules'][] = $schedule->id;
        $this->created['modules'][] = $module->id;
        return [$course, $module];
    }

    private function workshop(int $capacity = 10, string $start = '18:00:00', string $end = '20:00:00', int $day = 1): Taller
    {
        $workshop = Taller::create([
            'nombre' => 'Taller concurrente '.Str::uuid(),
            'modalidad' => 'presencial',
            'fecha' => now()->addDays(20)->toDateString(),
            'fecha_fin' => now()->addDays(80)->toDateString(),
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'capacidad_maxima' => $capacity,
            'precio' => 100,
            'estado' => 'confirmado',
        ]);
        $slot = HorarioTaller::create([
            'taller_id' => $workshop->id,
            'dia_semana' => $day,
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'capacidad' => $capacity,
        ]);
        $this->created['workshops'][] = $workshop->id;
        $this->created['workshop_schedules'][] = $slot->id;
        return $workshop;
    }

    private function payload(string $kind, Persona $student, string $offerId, ?string $moduleId = null): array
    {
        return $kind === 'workshop'
            ? ['estudiante_id' => $student->id, 'taller_id' => $offerId, 'monto_pagado' => 100, 'metodo_pago' => 'efectivo']
            : ($kind === 'custom'
                ? ['estudiante_id' => $student->id, 'curso_abierto_id' => $offerId, 'pago_inicial' => 100, 'metodo_pago' => 'efectivo']
                : ['estudiante_id' => $student->id, 'curso_abierto_id' => $offerId, 'pagos' => [['modulo_id' => $moduleId, 'monto' => 100]], 'metodo_pago' => 'efectivo']);
    }

    private function runPair(string $uri, array $payloadA, array $payloadB, ?string $uriB = null): array
    {
        $base = sys_get_temp_dir().'/concurrency-'.Str::uuid();
        $go = $base.'.go';
        $processes = [];
        foreach ([$payloadA, $payloadB] as $index => $payload) {
            $ready = $base.'.ready'.$index;
            $args = base64_encode(json_encode(['uri' => $index === 1 && $uriB ? $uriB : $uri, 'payload' => $payload, 'token' => $this->token, 'ready' => $ready, 'go' => $go]));
            $pipes = [];
            $processes[$index] = [proc_open('php '.escapeshellarg(base_path('tests/Support/concurrent_request.php')).' '.escapeshellarg($args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $ready];
        }
        $deadline = microtime(true) + 5;
        while ((!is_file($base.'.ready0') || !is_file($base.'.ready1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        touch($go);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $results[] = ['exit' => $exit, 'output' => json_decode(trim($output), true), 'error' => $error];
        }
        foreach (glob($base.'*') ?: [] as $file) @unlink($file);
        return $results;
    }

    private function commitFixture(): void
    {
        DB::commit();
    }

    private function resumeTransaction(): void
    {
        DB::beginTransaction();
        $this->created = [];
    }

    private function cleanupCreated(): void
    {
        $courseIds = collect($this->created['courses'] ?? []);
        $workshopIds = collect($this->created['workshops'] ?? []);
        $peopleIds = collect($this->created['people'] ?? []);
        $matriculaIds = Matricula::withTrashed()->whereIn('curso_abierto_id', $courseIds)->pluck('id');
        $inscripcionIds = InscripcionTaller::whereIn('taller_id', $workshopIds)->pluck('id');
        $lineIds = LineaPagoModulo::whereIn('matricula_id', $matriculaIds)->pluck('id');
        $accountIds = CuentaPorCobrar::where(function ($query) use ($matriculaIds, $inscripcionIds) {
            $query->whereIn('matricula_id', $matriculaIds)->orWhereIn('inscripcion_taller_id', $inscripcionIds);
        })->pluck('id');

        TransaccionIngreso::whereIn('cuenta_cobrar_id', $accountIds)->orWhereIn('linea_pago_modulo_id', $lineIds)->delete();
        LineaPagoModulo::whereIn('id', $lineIds)->delete();
        CuentaPorCobrar::whereIn('id', $accountIds)->delete();
        Matricula::withTrashed()->whereIn('id', $matriculaIds)->forceDelete();
        \App\Models\SolicitudInscripcion::whereIn('curso_abierto_id', $courseIds)->orWhereIn('persona_id', $peopleIds)->delete();
        InscripcionTaller::whereIn('id', $inscripcionIds)->delete();
        HorarioTaller::withTrashed()->whereIn('taller_id', $workshopIds)->forceDelete();

        $schedules = CursoAbierto::withTrashed()->whereIn('id', $courseIds)->pluck('horario_id')->filter();
        CursoAbierto::withTrashed()->whereIn('id', $courseIds)->update(['horario_id' => null]);
        HorarioDia::whereIn('horario_id', $schedules)->delete();
        Horario::whereIn('id', $schedules)->delete();
        Modulo::whereIn('curso_abierto_id', $courseIds)->delete();
        CursoAbierto::withTrashed()->whereIn('id', $courseIds)->forceDelete();
        CatalogoCurso::whereIn('id', $this->created['catalogs'] ?? [])->delete();
        Taller::whereIn('id', $workshopIds)->delete();
        Persona::whereIn('id', $peopleIds)->delete();
    }

    private function assertOneWinner(array $results): void
    {
        $this->assertCount(2, $results);
        $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 201)), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, count(array_filter($results, fn ($r) => ($r['exit'] ?? 1) !== 0)), json_encode($results, JSON_UNESCAPED_UNICODE));
    }

    public function test_real_concurrency_protects_last_seat_for_course_custom_and_workshop(): void
    {
        foreach (['normal', 'custom', 'workshop'] as $kind) {
            $a = $this->student();
            $b = $this->student();
            if ($kind === 'workshop') {
                $offer = $this->workshop(1);
                $uri = '/api/academic/inscripciones-talleres/inscribir-desde-perfil';
                $payloadA = $this->payload('workshop', $a, $offer->id);
                $payloadB = $this->payload('workshop', $b, $offer->id);
                $this->commitFixture();
                $results = $this->runPair($uri, $payloadA, $payloadB);
                $this->assertOneWinner($results);
                $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 422)));
                $this->assertSame(1, InscripcionTaller::where('taller_id', $offer->id)->where('estado', 'activo')->count());
            } else {
                [$offer, $module] = $this->course($kind === 'custom', 1);
                $uri = '/api/academic/matriculas/inscribir-desde-perfil';
                $payloadA = $this->payload($kind, $a, $offer->id, $module->id);
                $payloadB = $this->payload($kind, $b, $offer->id, $module->id);
                $this->commitFixture();
                $results = $this->runPair($uri, $payloadA, $payloadB);
                $this->assertOneWinner($results);
                $this->assertSame(1, Matricula::where('curso_abierto_id', $offer->id)->where('estado', 'activo')->count());
            }
            $this->cleanupCreated();
            $this->resumeTransaction();
        }
    }

    public function test_real_concurrency_serializes_duplicate_course_custom_and_workshop(): void
    {
        foreach (['normal', 'custom', 'workshop'] as $kind) {
            $student = $this->student();
            if ($kind === 'workshop') {
                $offer = $this->workshop(10);
                $uri = '/api/academic/inscripciones-talleres/inscribir-desde-perfil';
                $payload = $this->payload('workshop', $student, $offer->id);
            } else {
                [$offer, $module] = $this->course($kind === 'custom', 10);
                $uri = '/api/academic/matriculas/inscribir-desde-perfil';
                $payload = $this->payload($kind, $student, $offer->id, $module->id);
            }
            $this->commitFixture();
            $results = $this->runPair($uri, $payload, $payload);
            $this->assertOneWinner($results);
            $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 409)));
            if ($kind === 'workshop') {
                $this->assertSame(1, InscripcionTaller::where('taller_id', $offer->id)->where('persona_id', $student->id)->count());
            } else {
                $this->assertSame(1, Matricula::where('curso_abierto_id', $offer->id)->where('estudiante_id', $student->id)->count());
                $this->assertSame(1, CuentaPorCobrar::whereHas('matricula', fn ($q) => $q->where('curso_abierto_id', $offer->id)->where('estudiante_id', $student->id))->count());
            }
            $this->cleanupCreated();
            $this->resumeTransaction();
        }
    }

    public function test_real_concurrency_serializes_schedule_conflicts_and_allows_non_conflicts(): void
    {
        $student = $this->student();
        [$a, $moduleA] = $this->course(false, 10, '18:00:00', '20:00:00', 1);
        [$b, $moduleB] = $this->course(false, 10, '19:00:00', '21:00:00', 1);
        $this->commitFixture();
        $results = $this->runPair('/api/academic/matriculas/inscribir-desde-perfil', $this->payload('normal', $student, $a->id, $moduleA->id), $this->payload('normal', $student, $b->id, $moduleB->id));
        $this->assertOneWinner($results);
        $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 409)), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Matricula::where('estudiante_id', $student->id)->whereIn('curso_abierto_id', [$a->id, $b->id])->count());
        $this->cleanupCreated();
        $this->resumeTransaction();

        $student = $this->student();
        [$course, $module] = $this->course(false, 10, '18:00:00', '20:00:00', 1);
        $workshop = $this->workshop(10, '19:00:00', '21:00:00', 1);
        $this->commitFixture();
        $results = $this->runPair('/api/academic/matriculas/inscribir-desde-perfil', $this->payload('normal', $student, $course->id, $module->id), $this->payload('workshop', $student, $workshop->id), '/api/academic/inscripciones-talleres/inscribir-desde-perfil');
        $this->assertOneWinner($results);
        $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 409)), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Matricula::where('estudiante_id', $student->id)->count() + InscripcionTaller::where('persona_id', $student->id)->count());
        $this->cleanupCreated();
        $this->resumeTransaction();

        $student = $this->student();
        [$course, $module] = $this->course(false, 10, '18:00:00', '20:00:00', 1);
        [$other, $otherModule] = $this->course(false, 10, '18:00:00', '20:00:00', 2);
        $this->commitFixture();
        $results = $this->runPair('/api/academic/matriculas/inscribir-desde-perfil', $this->payload('normal', $student, $course->id, $module->id), $this->payload('normal', $student, $other->id, $otherModule->id));
        $this->assertSame(2, count(array_filter($results, fn ($r) => ($r['output']['status'] ?? 0) === 201)), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, Matricula::where('estudiante_id', $student->id)->count());
        $this->cleanupCreated();
        $this->resumeTransaction();
    }
}
