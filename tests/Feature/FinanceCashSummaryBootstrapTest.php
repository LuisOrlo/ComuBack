<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\TransaccionEgreso;
use App\Models\TransaccionIngreso;
use App\Models\Finance\LineaPagoModulo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceCashSummaryBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bootstrap_creates_the_singleton_with_zero_values(): void
    {
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();

        $this->assertNotNull($summary);
        $this->assertSame(1, DB::table('finance.resumen_caja')->count());
        $this->assertSame(0.0, (float) $summary->total_ingresos);
        $this->assertSame(0.0, (float) $summary->total_egresos);
        $this->assertSame(0.0, (float) $summary->saldo_actual);
    }

    public function test_repair_command_is_idempotent_and_does_not_reset_existing_values(): void
    {
        DB::table('finance.resumen_caja')->update([
            'total_ingresos' => 12,
            'total_egresos' => 3,
            'saldo_actual' => 9,
        ]);

        $this->artisan('finance:repair-cash-summary')
            ->assertExitCode(0);

        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertSame(12.0, (float) $summary->total_ingresos);
        $this->assertSame(3.0, (float) $summary->total_egresos);
        $this->assertSame(9.0, (float) $summary->saldo_actual);
        $this->assertSame(1, DB::table('finance.resumen_caja')->count());
    }

    public function test_approved_payment_updates_line_account_and_cash_once(): void
    {
        $fixture = $this->financialFixture(72);

        TransaccionIngreso::create([
            'cuenta_cobrar_id' => $fixture['cuenta']->id,
            'linea_pago_modulo_id' => $fixture['linea']->id,
            'monto' => 72,
            'metodo_pago' => 'efectivo',
            'fecha_pago' => '2020-01-15 12:00:00-05',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
        ]);

        $fixture['linea']->refresh();
        $fixture['cuenta']->refresh();
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();

        $this->assertSame(72.0, (float) $fixture['linea']->monto_abonado);
        $this->assertSame(72.0, (float) $fixture['cuenta']->monto_abonado);
        $this->assertSame(0.0, (float) $fixture['cuenta']->saldo_pendiente);
        $this->assertSame(72.0, (float) $summary->total_ingresos);
        $this->assertSame(72.0, (float) $summary->saldo_actual);
        $this->assertSame(1, TransaccionIngreso::where('cuenta_cobrar_id', $fixture['cuenta']->id)->count());
    }

    public function test_two_payments_accumulate_without_double_counting(): void
    {
        $fixture = $this->financialFixture(72);

        foreach ([30, 42] as $amount) {
            TransaccionIngreso::create([
                'cuenta_cobrar_id' => $fixture['cuenta']->id,
                'linea_pago_modulo_id' => $fixture['linea']->id,
                'monto' => $amount,
                'metodo_pago' => 'efectivo',
                'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
            ]);
        }

        $fixture['linea']->refresh();
        $fixture['cuenta']->refresh();
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();

        $this->assertSame(72.0, (float) $fixture['linea']->monto_abonado);
        $this->assertSame(72.0, (float) $fixture['cuenta']->monto_abonado);
        $this->assertSame(0.0, (float) $fixture['cuenta']->saldo_pendiente);
        $this->assertSame(72.0, (float) $summary->total_ingresos);
    }

    public function test_partial_payment_updates_cash_and_leaves_balance(): void
    {
        $fixture = $this->financialFixture(72);

        TransaccionIngreso::create([
            'cuenta_cobrar_id' => $fixture['cuenta']->id,
            'linea_pago_modulo_id' => $fixture['linea']->id,
            'monto' => 30,
            'metodo_pago' => 'transferencia',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
        ]);

        $fixture['linea']->refresh();
        $fixture['cuenta']->refresh();
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();

        $this->assertSame(30.0, (float) $fixture['linea']->monto_abonado);
        $this->assertSame(LineaPagoModulo::ESTADO_ABONADO, $fixture['linea']->estado);
        $this->assertSame(30.0, (float) $fixture['cuenta']->monto_abonado);
        $this->assertSame(42.0, (float) $fixture['cuenta']->saldo_pendiente);
        $this->assertSame(30.0, (float) $summary->total_ingresos);
    }

    public function test_egreso_update_and_delete_recalculate_global_cash(): void
    {
        $ingreso = TransaccionIngreso::create([
            'monto' => 72,
            'metodo_pago' => 'efectivo',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
        ]);

        $egreso = TransaccionEgreso::create([
            'descripcion' => 'Egreso de prueba',
            'monto' => 10,
            'metodo_pago' => 'transferencia',
        ]);

        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertSame(72.0, (float) $summary->total_ingresos);
        $this->assertSame(10.0, (float) $summary->total_egresos);
        $this->assertSame(62.0, (float) $summary->saldo_actual);

        $egreso->update(['monto' => 8]);
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertSame(8.0, (float) $summary->total_egresos);
        $this->assertSame(64.0, (float) $summary->saldo_actual);

        $egreso->delete();
        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertSame(0.0, (float) $summary->total_egresos);
        $this->assertSame(72.0, (float) $summary->saldo_actual);
        $this->assertDatabaseHas('finance.transacciones_ingreso', ['id' => $ingreso->id]);
    }

    public function test_repair_rebuilds_missing_summary_from_existing_movements(): void
    {
        DB::table('finance.resumen_caja')->delete();

        TransaccionIngreso::create([
            'monto' => 72,
            'metodo_pago' => 'efectivo',
            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
        ]);
        TransaccionEgreso::create([
            'descripcion' => 'Egreso histórico',
            'monto' => 12,
            'metodo_pago' => 'transferencia',
        ]);

        $this->assertSame(0, DB::table('finance.resumen_caja')->count());

        $this->artisan('finance:repair-cash-summary')
            ->assertExitCode(0);

        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertNotNull($summary);
        $this->assertSame(72.0, (float) $summary->total_ingresos);
        $this->assertSame(12.0, (float) $summary->total_egresos);
        $this->assertSame(60.0, (float) $summary->saldo_actual);

        $this->artisan('finance:repair-cash-summary')
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('finance.resumen_caja')->count());
    }

    public function test_rollback_does_not_leave_cash_summary_changes(): void
    {
        try {
            DB::transaction(function (): void {
                TransaccionEgreso::create([
                    'descripcion' => 'Egreso revertido',
                    'monto' => 25,
                    'metodo_pago' => 'efectivo',
                ]);

                throw new \RuntimeException('rollback controlado');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback controlado', $exception->getMessage());
        }

        $summary = DB::table('finance.resumen_caja')->where('id', 1)->first();
        $this->assertSame(0.0, (float) $summary->total_egresos);
        $this->assertSame(0.0, (float) $summary->saldo_actual);
        $this->assertSame(0, TransaccionEgreso::where('descripcion', 'Egreso revertido')->count());
    }

    private function financialFixture(float $total): array
    {
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'nombres' => 'Caja',
            'apellidos' => 'Prueba',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'es_activo' => true,
        ]);

        $catalogo = CatalogoCurso::create([
            'categoria' => 'regular',
            'nombre' => 'Caja test',
            'descripcion' => 'Fixture financiero',
            'modulos_default' => 1,
            'creditos' => 1,
            'horas_totales' => 1,
            'es_activo' => true,
        ]);

        $curso = \App\Models\CursoAbierto::create([
            'catalogo_curso_id' => $catalogo->id,
            'modalidad' => 'virtual',
            'capacidad_maxima' => 10,
            'precio_base' => $total,
            'es_personalizado' => false,
            'es_activo' => true,
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDays(2),
            'nombre_instancia' => 'Caja test',
        ]);

        $modulo = Modulo::create([
            'curso_abierto_id' => $curso->id,
            'nombre_modulo' => 'Módulo caja',
            'numero_orden' => 1,
            'precio_base' => $total,
        ]);

        $matricula = Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $curso->id,
            'precio_total_legacy' => 0,
            'tipo_pago' => 'completo',
            'estado' => Matricula::ESTADO_ACTIVO,
        ]);

        $linea = LineaPagoModulo::create([
            'matricula_id' => $matricula->id,
            'modulo_id' => $modulo->id,
            'tipo' => 'modulo',
            'monto_original' => $total,
            'monto_ajustado' => $total,
            'monto_abonado' => 0,
            'estado' => LineaPagoModulo::ESTADO_PENDIENTE,
            'orden' => 1,
        ]);

        $cuenta = CuentaPorCobrar::create([
            'matricula_id' => $matricula->id,
            'monto_total' => $total,
            'monto_abonado' => 0,
            'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
            'es_legacy' => false,
        ]);

        return compact('linea', 'cuenta');
    }
}
