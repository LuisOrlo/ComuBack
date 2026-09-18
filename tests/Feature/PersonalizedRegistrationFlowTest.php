<?php

namespace Tests\Feature;

use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\SolicitudInscripcion;
use App\Models\Finance\LineaPagoModulo;
use App\Models\TransaccionIngreso;
use App\Services\RegistrationStateService;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizedRegistrationFlowTest extends TestCase
{
    private function curso(array $overrides = []): CursoAbierto
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
            'nombre_instancia' => 'Curso personalizado de prueba',
            'fecha_inicio' => now()->addDays(3)->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
            'capacidad_maxima' => 2,
            'docente_id' => null,
            'es_activo' => true,
            'modalidad' => 'virtual',
            'precio_base' => 300,
        ], $overrides));
    }

    private function solicitud(CursoAbierto $curso, Persona $persona, float $declarado = 150): SolicitudInscripcion
    {
        return SolicitudInscripcion::create([
            'persona_id' => $persona->id,
            'participante_externo_id' => null,
            'es_participante_externo' => false,
            'curso_abierto_id' => $curso->id,
            'monto_solicitado' => $declarado,
            'tipo_pago' => 'abono',
            'tipo_comprobante' => 'efectivo',
            'fecha_pago_declarada' => now()->toDateString(),
            'estado' => SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION,
        ]);
    }

    private function persona(): Persona
    {
        return Persona::create([
            'tipo' => 'estudiante',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Estudiante',
            'apellidos' => 'Prueba',
            'correo' => Str::uuid() . '@example.test',
            'es_activo' => true,
        ]);
    }

    public function test_aprueba_pago_completo_y_cierra_la_cuenta(): void
    {
        $curso = $this->curso();
        $persona = $this->persona();
        $solicitud = $this->solicitud($curso, $persona, 150);

        $resultado = app(RegistrationStateService::class)->approve($solicitud, null, null, [], 'efectivo', null, 300);

        $this->assertTrue($resultado['exito']);
        $linea = LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->firstOrFail();
        $cuenta = CuentaPorCobrar::where('matricula_id', $resultado['matricula_id'])->firstOrFail();

        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->count());
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->whereNotNull('modulo_id')->count());
        $this->assertSame('inscripcion', $linea->tipo);
        $this->assertNull($linea->modulo_id);
        $this->assertEquals(300, (float) $linea->monto_ajustado);
        $this->assertEquals(300, (float) $linea->monto_abonado);
        $this->assertEquals(300, (float) $cuenta->monto_abonado);
        $this->assertSame(CuentaPorCobrar::ESTADO_PAGADO, $cuenta->estado);
        $this->assertDatabaseHas('finance.transacciones_ingreso', ['linea_pago_modulo_id' => $linea->id, 'monto' => 300]);
        $this->assertEquals(150, (float) $solicitud->fresh()->monto_solicitado);
    }

    public function test_aprueba_abono_y_conserva_el_monto_declarado(): void
    {
        $curso = $this->curso();
        $persona = $this->persona();
        $solicitud = $this->solicitud($curso, $persona, 150);

        $resultado = app(RegistrationStateService::class)->approve($solicitud, null, null, [], 'efectivo', null, 100);

        $this->assertTrue($resultado['exito']);
        $linea = LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->firstOrFail();
        $cuenta = CuentaPorCobrar::where('matricula_id', $resultado['matricula_id'])->firstOrFail();

        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->count());
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $resultado['matricula_id'])->whereNotNull('modulo_id')->count());
        $this->assertEquals(300, (float) $cuenta->monto_total);
        $this->assertEquals(100, (float) $cuenta->monto_abonado);
        $this->assertEquals(200, (float) $cuenta->saldo_pendiente);
        $this->assertSame(CuentaPorCobrar::ESTADO_ABONADO, $cuenta->estado);
        $this->assertEquals(100, (float) $linea->monto_abonado);
        $this->assertEquals(100, (float) TransaccionIngreso::where('linea_pago_modulo_id', $linea->id)->sum('monto'));
        $this->assertEquals(150, (float) $solicitud->fresh()->monto_solicitado);
    }

    public function test_rechaza_pago_cero_y_pago_superior_al_precio(): void
    {
        foreach ([0, 301] as $pago) {
            $curso = $this->curso();
            $persona = $this->persona();
            $solicitud = $this->solicitud($curso, $persona);

            $resultado = app(RegistrationStateService::class)->approve($solicitud, null, null, [], 'efectivo', null, $pago);

            $this->assertFalse($resultado['exito']);
            $this->assertDatabaseMissing('academic.matriculas', ['solicitud_inscripcion_id' => $solicitud->id]);
            $this->assertDatabaseMissing('finance.cuentas_por_cobrar', ['matricula_id' => $resultado['matricula_id']]);
        }
    }

    public function test_rechazo_no_crea_registros_financieros(): void
    {
        $curso = $this->curso();
        $persona = $this->persona();
        $solicitud = $this->solicitud($curso, $persona);

        $resultado = app(RegistrationStateService::class)->reject($solicitud, null, 'Datos no válidos');

        $this->assertTrue($resultado['exito']);
        $this->assertDatabaseMissing('academic.matriculas', ['solicitud_inscripcion_id' => $solicitud->id]);
        $this->assertDatabaseCount('finance.lineas_pago_modulo', 0);
        $this->assertDatabaseCount('finance.transacciones_ingreso', 0);
    }

    public function test_no_aprueba_curso_lleno_ni_matricula_duplicada(): void
    {
        $cursoLleno = $this->curso(['capacidad_maxima' => 1]);
        $ocupante = $this->persona();
        Matricula::create([
            'estudiante_id' => $ocupante->id,
            'curso_abierto_id' => $cursoLleno->id,
            'estado' => 'activo',
            'precio_total_legacy' => 0,
        ]);
        $solicitudLlena = $this->solicitud($cursoLleno, $this->persona());

        $resultadoLleno = app(RegistrationStateService::class)->approve($solicitudLlena, null, null, [], 'efectivo', null, 100);
        $this->assertFalse($resultadoLleno['exito']);

        $curso = $this->curso();
        $persona = $this->persona();
        Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $curso->id,
            'estado' => 'activo',
            'precio_total_legacy' => 0,
        ]);
        $solicitudDuplicada = $this->solicitud($curso, $persona);

        $resultadoDuplicado = app(RegistrationStateService::class)->approve($solicitudDuplicada, null, null, [], 'efectivo', null, 100);
        $this->assertFalse($resultadoDuplicado['exito']);
    }
}
