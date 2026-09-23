<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairFinanceCashSummary extends Command
{
    protected $signature = 'finance:repair-cash-summary
                            {--allow-db1 : Permite ejecutar explícitamente sobre DB1}';

    protected $description = 'Inicializa de forma segura el resumen singleton de caja usando los movimientos existentes';

    public function handle(): int
    {
        $database = strtolower((string) config('database.connections.pgsql.database'));

        if ($database === 'db1' && ! $this->option('allow-db1')) {
            $this->error('Operación abortada: DB1 está protegida. Use --allow-db1 solo con autorización explícita.');

            return self::FAILURE;
        }

        $result = DB::connection('pgsql')->transaction(function (): array {
            $summary = DB::table('finance.resumen_caja')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($summary) {
                return [
                    'created' => false,
                    'total_ingresos' => (float) $summary->total_ingresos,
                    'total_egresos' => (float) $summary->total_egresos,
                    'saldo_actual' => (float) $summary->saldo_actual,
                ];
            }

            $totalIngresos = (float) DB::table('finance.transacciones_ingreso')->sum('monto');
            $totalEgresos = (float) DB::table('finance.transacciones_egreso')->sum('monto');
            $saldoActual = $totalIngresos - $totalEgresos;

            DB::table('finance.resumen_caja')->insert([
                'id' => 1,
                'total_ingresos' => $totalIngresos,
                'total_egresos' => $totalEgresos,
                'saldo_actual' => $saldoActual,
            ]);

            return [
                'created' => true,
                'total_ingresos' => $totalIngresos,
                'total_egresos' => $totalEgresos,
                'saldo_actual' => $saldoActual,
            ];
        });

        if ($result['created']) {
            $this->info('Resumen de caja creado a partir de los movimientos existentes.');
        } else {
            $this->info('El resumen de caja ya existe; no se modificaron sus valores.');
        }

        $this->table(
            ['Total ingresos', 'Total egresos', 'Saldo actual'],
            [[
                number_format($result['total_ingresos'], 2, '.', ''),
                number_format($result['total_egresos'], 2, '.', ''),
                number_format($result['saldo_actual'], 2, '.', ''),
            ]]
        );

        return self::SUCCESS;
    }
}
