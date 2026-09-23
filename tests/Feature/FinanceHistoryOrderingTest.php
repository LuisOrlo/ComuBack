<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FinanceController;
use App\Models\CatalogoCurso;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\TransaccionIngreso;
use App\Models\Finance\LineaPagoModulo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceHistoryOrderingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_same_declared_date_is_ordered_by_registration_timestamp(): void
    {
        $fixture = $this->fixture();
        $older = $this->transaction($fixture, 'curso-older-' . Str::uuid(), '09:00:00');
        $newer = $this->transaction($fixture, 'servicio-newer-' . Str::uuid(), '09:10:00');

        $response = app(FinanceController::class)->getHistorial(
            Request::create('/api/finanzas/historial', 'GET', ['per_page' => 100])
        );
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $ids = collect($data)->pluck('id')->values()->all();

        $this->assertLessThan(
            array_search($older->id, $ids, true),
            array_search($newer->id, $ids, true)
        );
    }

    private function transaction(array $fixture, string $reference, string $time): TransaccionIngreso
    {
        $timestamp = "2026-09-23 {$time}-05";

        return TransaccionIngreso::create([
            'cuenta_cobrar_id' => $fixture['cuenta']->id,
            'linea_pago_modulo_id' => $fixture['linea']->id,
            'referencia_pago' => $reference,
            'monto' => 10,
            'metodo_pago' => 'transferencia',
            'fecha_pago' => '2026-09-23 00:00:00-05',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
            'fecha_verificacion' => $timestamp,
        ]);
    }

    private function fixture(): array
    {
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'nombres' => 'Orden',
            'apellidos' => 'Financiero',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'es_activo' => true,
        ]);
        $catalogo = CatalogoCurso::create([
            'categoria' => 'regular',
            'nombre' => 'Orden test',
            'descripcion' => 'Fixture de orden',
            'modulos_default' => 1,
            'creditos' => 1,
            'horas_totales' => 1,
            'es_activo' => true,
        ]);
        $curso = \App\Models\CursoAbierto::create([
            'catalogo_curso_id' => $catalogo->id,
            'modalidad' => 'virtual',
            'capacidad_maxima' => 10,
            'precio_base' => 10,
            'es_personalizado' => false,
            'es_activo' => true,
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDays(2),
            'nombre_instancia' => 'Orden test',
        ]);
        $modulo = Modulo::create([
            'curso_abierto_id' => $curso->id,
            'nombre_modulo' => 'Módulo orden',
            'numero_orden' => 1,
            'precio_base' => 10,
        ]);
        $matricula = Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $curso->id,
            'precio_total_legacy' => 10,
            'tipo_pago' => 'completo',
            'estado' => Matricula::ESTADO_ACTIVO,
        ]);
        $linea = LineaPagoModulo::create([
            'matricula_id' => $matricula->id,
            'modulo_id' => $modulo->id,
            'tipo' => 'modulo',
            'monto_original' => 10,
            'monto_ajustado' => 10,
            'monto_abonado' => 0,
            'estado' => LineaPagoModulo::ESTADO_PENDIENTE,
            'orden' => 1,
        ]);
        $cuenta = CuentaPorCobrar::create([
            'matricula_id' => $matricula->id,
            'monto_total' => 10,
            'monto_abonado' => 0,
            'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
            'es_legacy' => false,
        ]);

        return compact('cuenta', 'linea');
    }
}
