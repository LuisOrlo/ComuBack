<?php

namespace App\Services\Imports;

use App\DTOs\Imports\FinancialImportDataDTO;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Finance\LineaPagoModulo;
use App\Models\TransaccionIngreso;
use Illuminate\Support\Facades\DB;

final class FinanceImportAdapter
{
    public function __construct(private readonly FinancialImportValidator $validator) {}

    public function preview(Matricula $matricula, FinancialImportDataDTO $data): array
    {
        $course = $matricula->cursoAbierto()->first();
        $errors = $this->cashSummaryErrors();
        $errors = array_merge($errors, $this->validator->validateCourse($course));

        if (!$this->hasExistingFinance($matricula->id)) {
            $validation = $course ? $this->validator->validateModules($data->modules, $course, $data->paymentOptions->toArray()) : ['errors' => [], 'warnings' => []];
            $errors = array_merge($errors, $validation['errors']);
            $warnings = $validation['warnings'];
        } else {
            $errors[] = $this->error('FINANCE_CONFLICT', 'La matrícula ya tiene información financiera y no será modificada.');
            $warnings = [];
        }

        return [
            'status' => $errors ? 'BLOCKED' : ($data->activeModules() ? 'READY' : 'NO_FINANCE_REQUIRED'),
            'errors' => $errors,
            'warnings' => $warnings,
            'financial_modules' => $data->modules,
            'total' => $data->total(),
            'paid' => $data->paid(),
            'balance' => $data->balance(),
            'transactions_expected' => count(array_filter($data->modules, fn (array $module): bool => (float) ($module['paid'] ?? 0) > 0)),
        ];
    }

    public function execute(
        Matricula $matricula,
        FinancialImportDataDTO $data,
        string $importId,
        int $rowNumber,
        ?string $registeredBy = null,
    ): array {
        $summaryBefore = DB::table('finance.resumen_caja')->where('id', 1)->lockForUpdate()->first();
        if (!$summaryBefore) {
            throw new \RuntimeException('FINANCIAL_CASH_SUMMARY_NOT_INITIALIZED');
        }

        $lockedMatricula = Matricula::withTrashed()->whereKey($matricula->id)->lockForUpdate()->first();
        if (!$lockedMatricula || $lockedMatricula->trashed()) {
            throw new \RuntimeException('La matrícula no existe o fue eliminada.');
        }

        $course = $lockedMatricula->cursoAbierto()->first();
        $courseErrors = $this->validator->validateCourse($course);
        if ($courseErrors) throw new \RuntimeException($courseErrors[0]['code']);

        if ($this->hasExistingFinance($lockedMatricula->id)) {
            throw new \RuntimeException('FINANCE_CONFLICT');
        }

        $validation = $this->validator->validateModules($data->modules, $course, $data->paymentOptions->toArray());
        if ($validation['errors']) throw new \RuntimeException($validation['errors'][0]['code']);

        $activeModules = $data->activeModules();
        if (!$activeModules) {
            return [
                'finance_status' => 'NO_FINANCE_REQUIRED',
                'financial_lines_created' => 0,
                'accounts_created' => 0,
                'transactions_created' => 0,
                'financial_warnings' => $validation['warnings'],
            ];
        }

        $courseModules = $course->modulos()->whereIn('id', array_column($activeModules, 'modulo_id'))->get()->keyBy('id');
        $lines = [];
        $total = 0.0;
        foreach ($activeModules as $moduleData) {
            $module = $courseModules->get((string) $moduleData['modulo_id']);
            if (!$module) throw new \RuntimeException('MODULE_MAPPING_INVALID');
            $adjusted = round((float) $moduleData['total'], 2);
            $original = $module->precio_base !== null ? round((float) $module->precio_base, 2) : $adjusted;
            $line = LineaPagoModulo::create([
                'matricula_id' => $lockedMatricula->id,
                'modulo_id' => $module->id,
                'tipo' => 'modulo',
                'monto_original' => $original,
                'monto_ajustado' => $adjusted,
                'motivo_ajuste' => abs($original - $adjusted) > 0.01 ? 'Valor histórico importado desde Excel' : null,
                'monto_abonado' => 0,
                'estado' => LineaPagoModulo::ESTADO_PENDIENTE,
                'orden' => $module->numero_orden ?? 0,
            ]);
            $lines[(string) $module->id] = ['model' => $line, 'data' => $moduleData];
            $total += $adjusted;
        }

        $account = CuentaPorCobrar::create([
            'matricula_id' => $lockedMatricula->id,
            'monto_total' => round($total, 2),
            'monto_abonado' => 0,
            'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
            'es_legacy' => false,
        ]);

        $transactions = [];
        foreach ($lines as $moduleId => $lineData) {
            $paid = round((float) $lineData['data']['paid'], 2);
            if ($paid <= 0) continue;

            $reference = "IMPORT-{$importId}-ROW-{$rowNumber}-MODULE-{$moduleId}";
            if (strlen($reference) > 100) throw new \RuntimeException('IMPORT_REFERENCE_TOO_LONG');
            if (TransaccionIngreso::where('referencia_pago', $reference)->exists()) {
                throw new \RuntimeException('FINANCE_CONFLICT');
            }

            $transaction = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $account->id,
                'linea_pago_modulo_id' => $lineData['model']->id,
                'referencia_pago' => $reference,
                'monto' => $paid,
                'metodo_pago' => $data->paymentOptions->metodoPago,
                'comprobante_url' => $data->paymentOptions->comprobanteUrl,
                'fecha_pago' => $data->paymentOptions->fechaPago,
                'registrado_por' => $registeredBy,
                'observaciones' => $data->paymentOptions->observaciones ?: 'Pago histórico importado desde Excel.',
                'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                'verificado_por' => $registeredBy,
                'fecha_verificacion' => now(),
            ]);
            $transactions[] = $transaction;
        }

        $expectedPaid = $data->paid();
        foreach ($lines as $lineData) {
            $line = $lineData['model']->fresh();
            $expected = (float) $lineData['data']['paid'];
            if (abs((float) $line->monto_abonado - $expected) > 0.01) throw new \RuntimeException('FINANCIAL_POST_PERSISTENCE_MISMATCH');
            if (abs($line->saldo_pendiente - (float) $lineData['data']['balance']) > 0.01) throw new \RuntimeException('FINANCIAL_POST_PERSISTENCE_MISMATCH');
        }

        $account->refresh();
        if (abs((float) $account->monto_total - $data->total()) > 0.01
            || abs((float) $account->monto_abonado - $expectedPaid) > 0.01
            || abs((float) $account->saldo_pendiente - $data->balance()) > 0.01) {
            throw new \RuntimeException('FINANCIAL_POST_PERSISTENCE_MISMATCH');
        }

        $summaryAfter = DB::table('finance.resumen_caja')->where('id', 1)->first();
        if (abs(((float) $summaryAfter->total_ingresos - (float) $summaryBefore->total_ingresos) - $expectedPaid) > 0.01) {
            throw new \RuntimeException('FINANCIAL_CASH_SUMMARY_MISMATCH');
        }

        return [
            'finance_status' => 'FINANCE_CREATED',
            'financial_lines_created' => count($lines),
            'accounts_created' => 1,
            'transactions_created' => count($transactions),
            'financial_warnings' => $validation['warnings'],
            'financial_transaction_ids' => array_map(fn (TransaccionIngreso $transaction): string => (string) $transaction->id, $transactions),
        ];
    }

    public function hasExistingFinance(string $matriculaId): bool
    {
        $lineIds = LineaPagoModulo::where('matricula_id', $matriculaId)->pluck('id');
        if ($lineIds->isNotEmpty()) return true;
        $accountIds = CuentaPorCobrar::where('matricula_id', $matriculaId)->pluck('id');
        if ($accountIds->isNotEmpty()) return true;
        return TransaccionIngreso::whereIn('linea_pago_modulo_id', $lineIds)
            ->orWhereIn('cuenta_cobrar_id', $accountIds)
            ->exists();
    }

    private function cashSummaryErrors(): array
    {
        return DB::table('finance.resumen_caja')->where('id', 1)->exists()
            ? []
            : [$this->error('FINANCIAL_CASH_SUMMARY_NOT_INITIALIZED', 'El resumen de caja no está inicializado.')];
    }

    private function error(string $code, string $message): array
    {
        return compact('code', 'message');
    }
}
