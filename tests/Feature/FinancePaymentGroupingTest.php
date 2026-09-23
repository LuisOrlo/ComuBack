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

class FinancePaymentGroupingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approval_payment_groups_different_seconds_and_includes_enrollment(): void
    {
        $fixture = $this->paymentFixture();
        $reference = 'mat-' . $fixture['matricula']->id . '-' . now()->timestamp;
        $comprobante = 'comprobante-test-' . Str::uuid() . '.jpeg';

        $transactions = collect([
            [$fixture['lineas'][0], 40, '2026-09-23 08:59:17-05', $reference],
            [$fixture['lineas'][1], 20, '2026-09-23 08:59:18-05', $reference],
            [$fixture['lineas'][2], 5, '2026-09-23 08:59:18-05', $reference . '-insc'],
        ])->map(function (array $data) use ($fixture, $comprobante) {
            return TransaccionIngreso::create([
                'cuenta_cobrar_id' => $fixture['cuenta']->id,
                'linea_pago_modulo_id' => $data[0]->id,
                'referencia_pago' => $data[3],
                'monto' => $data[1],
                'metodo_pago' => 'transferencia',
                'comprobante_url' => $comprobante,
                'fecha_pago' => $data[2],
                'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                'fecha_verificacion' => $data[2],
            ]);
        });
        $reference = $transactions->first()->referencia_pago;
        $laterReference = 'pago-' . Str::uuid();
        $laterTransaction = TransaccionIngreso::create([
            'cuenta_cobrar_id' => $fixture['cuenta']->id,
            'linea_pago_modulo_id' => $fixture['lineas'][0]->id,
            'referencia_pago' => $laterReference,
            'monto' => 10,
            'metodo_pago' => 'transferencia',
            'comprobante_url' => $comprobante,
            'fecha_pago' => '2026-09-23 08:59:18-05',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
            'fecha_verificacion' => '2026-09-23 08:59:18-05',
        ]);

        $controller = app(FinanceController::class);
        $history = json_decode(
            $controller->getHistorial(Request::create('/api/finanzas/historial', 'GET', ['per_page' => 100]))->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $group = collect($history['data'])->firstWhere('group_key', $reference);

        $this->assertNotNull($group);
        $this->assertSame(65.0, (float) $group['monto']);
        $this->assertSame(3, $group['count']);
        $this->assertCount(3, $group['detalle_conceptos']);
        $this->assertSame(
            ['Módulo 1', 'Módulo 2', 'Inscripción / Matrícula'],
            collect($group['detalle_conceptos'])->pluck('nombre')->all()
        );
        $laterGroup = collect($history['data'])->first(
            fn (array $item) => in_array($laterTransaction->id, $item['ids'] ?? [], true)
        );
        $this->assertNotNull($laterGroup);
        $this->assertSame(10.0, (float) $laterGroup['monto']);
        $this->assertSame(1, $laterGroup['count']);

        foreach ($transactions as $transaction) {
            $detail = json_decode(
                $controller->getTransaccionDetalle($transaction->id)->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR
            )['datos'];

            $this->assertSame(65.0, (float) $detail['monto']);
            $this->assertSame(3, $detail['count']);
            $this->assertCount(3, $detail['detalle_conceptos']);
        }
    }

    private function paymentFixture(): array
    {
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'nombres' => 'Agrupación',
            'apellidos' => 'Financiera',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'es_activo' => true,
        ]);

        $catalogo = CatalogoCurso::create([
            'categoria' => 'regular',
            'nombre' => 'Agrupación financiera test',
            'descripcion' => 'Fixture de agrupación',
            'modulos_default' => 2,
            'creditos' => 1,
            'horas_totales' => 1,
            'es_activo' => true,
        ]);
        $curso = \App\Models\CursoAbierto::create([
            'catalogo_curso_id' => $catalogo->id,
            'modalidad' => 'virtual',
            'capacidad_maxima' => 10,
            'precio_base' => 80,
            'es_personalizado' => false,
            'es_activo' => true,
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDays(2),
            'nombre_instancia' => 'Agrupación financiera test',
        ]);
        $modulos = collect([1, 2])->map(fn (int $order) => Modulo::create([
            'curso_abierto_id' => $curso->id,
            'nombre_modulo' => "Módulo {$order}",
            'numero_orden' => $order,
            'precio_base' => 40,
        ]));
        $matricula = Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $curso->id,
            'precio_total_legacy' => 90,
            'tipo_pago' => 'abono',
            'estado' => Matricula::ESTADO_ACTIVO,
        ]);
        $lineas = collect([
            [$modulos[0]->id, 'modulo', 40, 40, 1],
            [$modulos[1]->id, 'modulo', 40, 20, 2],
            [null, 'inscripcion', 10, 5, 999],
        ])->map(fn (array $linea) => LineaPagoModulo::create([
            'matricula_id' => $matricula->id,
            'modulo_id' => $linea[0],
            'tipo' => $linea[1],
            'monto_original' => $linea[2],
            'monto_ajustado' => $linea[2],
            'monto_abonado' => $linea[3],
            'estado' => LineaPagoModulo::ESTADO_ABONADO,
            'orden' => $linea[4],
        ]));
        $cuenta = CuentaPorCobrar::create([
            'matricula_id' => $matricula->id,
            'monto_total' => 90,
            'monto_abonado' => 65,
            'estado' => CuentaPorCobrar::ESTADO_ABONADO,
            'es_legacy' => false,
        ]);

        return compact('matricula', 'lineas', 'cuenta');
    }
}
