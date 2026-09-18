<?php

namespace Tests\Feature;

use App\Models\CuentaPorCobrar;
use App\Models\CuentaSistema;
use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Finance\LineaPagoModulo;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\TransaccionIngreso;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InscribirEstudianteAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function autenticar(): void
    {
        $persona = Persona::create([
            'tipo' => 'staff',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Administrador',
            'apellidos' => 'Prueba',
            'correo' => Str::uuid() . '@example.test',
            'es_activo' => true,
        ]);

        $cuenta = CuentaSistema::create([
            'persona_id' => $persona->id,
            'username' => Str::uuid()->toString(),
            'password_hash' => Hash::make('secret'),
        ]);
        $cuenta->assignRole(Role::findOrCreate('Administrador', 'web'));
        $this->actingAs($cuenta, 'sanctum');
    }

    private function estudiante(): Persona
    {
        return Persona::create([
            'tipo' => 'estudiante',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Estudiante',
            'apellidos' => 'Existente',
            'correo' => Str::uuid() . '@example.test',
            'es_activo' => true,
        ]);
    }

    private function personalizado(array $overrides = []): CursoAbierto
    {
        $catalogo = CatalogoCurso::create([
            'categoria' => 'personalizado',
            'nombre' => 'Catálogo ' . Str::uuid(),
            'creditos' => 3,
            'horas_totales' => 40,
            'es_activo' => true,
        ]);

        return CursoAbierto::create(array_merge([
            'catalogo_curso_id' => $catalogo->id,
            'es_personalizado' => true,
            'nombre_instancia' => 'Personalizado administrativo',
            'modalidad' => 'virtual',
            'fecha_inicio' => now()->addDays(3)->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
            'precio_base' => 300,
            'capacidad_maxima' => 5,
            'es_activo' => true,
        ], $overrides));
    }

    private function matriculaAnterior(Persona $estudiante, CursoAbierto $curso, string $estado, bool $eliminada = false): Matricula
    {
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_abierto_id' => $curso->id,
            'estado' => $estado,
            'precio_total_legacy' => 0,
        ]);

        if ($eliminada) {
            $matricula->delete();
        }

        return $matricula;
    }

    public function test_inscribe_personalizado_con_pago_completo_y_crea_finanzas(): void
    {
        $this->autenticar();
        $estudiante = $this->estudiante();
        $curso = $this->personalizado();

        $response = $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
            'estudiante_id' => $estudiante->id,
            'curso_abierto_id' => $curso->id,
            'pago_inicial' => 300,
            'metodo_pago' => 'efectivo',
        ]);

        $response->assertCreated();
        $matriculaId = $response->json('data.matricula_id');
        $linea = LineaPagoModulo::where('matricula_id', $matriculaId)->where('tipo', 'inscripcion')->firstOrFail();
        $cuenta = CuentaPorCobrar::where('matricula_id', $matriculaId)->firstOrFail();

        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $matriculaId)->whereNotNull('modulo_id')->count());
        $this->assertEquals(300, (float) $linea->monto_abonado);
        $this->assertSame(CuentaPorCobrar::ESTADO_PAGADO, $cuenta->estado);
        $this->assertDatabaseHas('finance.transacciones_ingreso', [
            'linea_pago_modulo_id' => $linea->id,
            'monto' => 300,
        ]);
    }

    public function test_inscribe_personalizado_con_abono_y_rechaza_valores_invalidos(): void
    {
        $this->autenticar();
        $estudiante = $this->estudiante();
        $curso = $this->personalizado();

        $response = $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
            'estudiante_id' => $estudiante->id,
            'curso_abierto_id' => $curso->id,
            'pago_inicial' => 100,
            'metodo_pago' => 'transferencia',
        ]);

        $response->assertCreated();
        $matriculaId = $response->json('data.matricula_id');
        $cuenta = CuentaPorCobrar::where('matricula_id', $matriculaId)->firstOrFail();
        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $matriculaId)->whereNotNull('modulo_id')->count());
        $this->assertEquals(200, (float) $cuenta->saldo_pendiente);
        $this->assertSame(CuentaPorCobrar::ESTADO_ABONADO, $cuenta->estado);

        $invalidCourse = $this->personalizado();
        $invalidStudent = $this->estudiante();
        foreach ([0, 301] as $pago) {
            $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
                'estudiante_id' => $invalidStudent->id,
                'curso_abierto_id' => $invalidCourse->id,
                'pago_inicial' => $pago,
                'metodo_pago' => 'efectivo',
            ])->assertStatus(422);
        }

        $this->assertSame(1, TransaccionIngreso::where('monto', 100)->count());
    }

    public function test_personalizado_no_duplica_matricula_para_el_mismo_estudiante(): void
    {
        $this->autenticar();
        $estudiante = $this->estudiante();
        $curso = $this->personalizado();
        $payload = [
            'estudiante_id' => $estudiante->id,
            'curso_abierto_id' => $curso->id,
            'pago_inicial' => 100,
            'metodo_pago' => 'efectivo',
        ];

        $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', $payload)->assertCreated();
        $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', $payload)->assertStatus(409);
        $this->assertDatabaseCount('academic.matriculas', 1);
    }

    public function test_bloquea_matricula_activa_completada_y_soft_deleted(): void
    {
        $this->autenticar();
        foreach ([Matricula::ESTADO_ACTIVO, Matricula::ESTADO_COMPLETADO] as $estado) {
            $estudiante = $this->estudiante();
            $curso = $this->personalizado();
            $this->matriculaAnterior($estudiante, $curso, $estado);

            $response = $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
                'estudiante_id' => $estudiante->id,
                'curso_abierto_id' => $curso->id,
                'pago_inicial' => 100,
                'metodo_pago' => 'efectivo',
            ]);

            $response->assertConflict();
        }

        $this->autenticar();
        $estudiante = $this->estudiante();
        $curso = $this->personalizado();
        $this->matriculaAnterior($estudiante, $curso, Matricula::ESTADO_RETIRADO, true);

        $response = $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
            'estudiante_id' => $estudiante->id,
            'curso_abierto_id' => $curso->id,
            'pago_inicial' => 100,
            'metodo_pago' => 'efectivo',
        ]);

        $response->assertConflict()
            ->assertJsonPath('mensaje', 'Existe una matrícula eliminada para este estudiante y curso. Requiere revisión administrativa.');
    }

    public function test_retirado_y_reprobado_permiten_nueva_matricula_con_finanzas_independientes(): void
    {
        foreach ([Matricula::ESTADO_RETIRADO, Matricula::ESTADO_REPROBADO] as $estado) {
            $this->autenticar();
            $estudiante = $this->estudiante();
            $curso = $this->personalizado();
            $anterior = $this->matriculaAnterior($estudiante, $curso, $estado);

            $response = $this->postJson('/api/academic/matriculas/inscribir-desde-perfil', [
                'estudiante_id' => $estudiante->id,
                'curso_abierto_id' => $curso->id,
                'pago_inicial' => 100,
                'metodo_pago' => 'efectivo',
            ]);

            $response->assertCreated();
            $nuevaId = $response->json('data.matricula_id');
            $this->assertNotSame($anterior->id, $nuevaId);
            $this->assertSame($estado, $anterior->fresh()->estado);
            $this->assertSame(Matricula::ESTADO_ACTIVO, Matricula::findOrFail($nuevaId)->estado);
            $this->assertNotNull(CuentaPorCobrar::where('matricula_id', $nuevaId)->first());
            $this->assertSame(1, CuentaPorCobrar::where('matricula_id', $nuevaId)->count());
        }
    }

    public function test_evalua_todo_el_historial_y_no_solo_la_primera_matricula(): void
    {
        $estudiante = $this->estudiante();
        $curso = $this->personalizado();
        $this->matriculaAnterior($estudiante, $curso, Matricula::ESTADO_RETIRADO);
        $activa = $this->matriculaAnterior($estudiante, $curso, Matricula::ESTADO_ACTIVO);

        $conflicto = Matricula::conflictoNuevaInscripcion($estudiante->id, $curso->id);

        $this->assertSame('activo', $conflicto['tipo']);
        $this->assertStringContainsString('matrícula activa', $conflicto['mensaje']);

        $activa->update(['estado' => Matricula::ESTADO_REPROBADO]);
        $this->assertNull(Matricula::conflictoNuevaInscripcion($estudiante->id, $curso->id));
    }
}
