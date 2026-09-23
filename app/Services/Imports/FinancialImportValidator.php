<?php

namespace App\Services\Imports;

use App\Models\CursoAbierto;
use App\Models\Modulo;
use Carbon\Carbon;

final class FinancialImportValidator
{
    public const METHODS = ['efectivo', 'transferencia', 'deposito', 'tarjeta', 'otro'];

    public function validateOptions(array $options, bool $enrollmentEnabled, ?string $courseId, array $headers): array
    {
        if (!filter_var($options['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $errors = [];
        if (!$enrollmentEnabled || !$courseId) {
            $errors[] = $this->error('FINANCE_REQUIRES_ENROLLMENT', 'Las finanzas requieren una matrícula y un curso seleccionado.');
        }

        if (!filter_var($options['confirm_real_financial_impact'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $errors[] = $this->error('FINANCIAL_IMPACT_CONFIRMATION_REQUIRED', 'Debe confirmar que los pagos afectarán caja y reportes financieros.');
        }

        $payment = $options['payment_options'] ?? [];
        if (!in_array($payment['metodo_pago'] ?? null, self::METHODS, true)) {
            $errors[] = $this->error('PAYMENT_METHOD_REQUIRED', 'Debe seleccionar un método de pago válido.');
        }

        $date = $payment['fecha_pago_default'] ?? null;
        if (!$date) {
            $errors[] = $this->error('PAYMENT_DATE_REQUIRED', 'Debe indicar la fecha histórica de pago.');
        } else {
            try {
                if (Carbon::parse($date)->isFuture()) {
                    $errors[] = $this->error('PAYMENT_DATE_IN_FUTURE', 'La fecha de pago no puede ser futura.');
                }
            } catch (\Throwable) {
                $errors[] = $this->error('PAYMENT_DATE_INVALID', 'La fecha de pago no es válida.');
            }
        }

        $groups = $options['groups'] ?? [];
        if (!$groups) {
            $errors[] = $this->error('MODULE_MAPPING_REQUIRED', 'Debe mapear al menos un grupo Excel a un módulo.');
        }

        $mappedColumns = [];
        foreach ($groups as $index => $group) {
            foreach (['total_column', 'paid_column', 'balance_column'] as $columnKey) {
                $column = $group[$columnKey] ?? null;
                if (!$column || !in_array((string) $column, $headers, true)) {
                    $errors[] = $this->error('FINANCIAL_COLUMN_INVALID', "La columna financiera {$columnKey} del grupo {$index} no existe.");
                }
                if ($column) $mappedColumns[] = (string) $column;
            }
            if (empty($group['modulo_id'])) {
                $errors[] = $this->error('MODULE_MAPPING_INVALID', "El grupo financiero {$index} no tiene módulo asignado.");
            }
        }

        $moduleIds = array_filter(array_map(fn (array $group): ?string => $group['modulo_id'] ?? null, $groups));
        if (count($moduleIds) !== count(array_unique($moduleIds))) {
            $errors[] = $this->error('MODULE_MAPPING_DUPLICATE', 'Un módulo del curso no puede recibir dos grupos Excel.');
        }

        return $errors;
    }

    public function validateCourse(?CursoAbierto $course): array
    {
        if (!$course) return [$this->error('COURSE_NOT_FOUND', 'El curso seleccionado no existe o fue eliminado.')];
        if ($course->es_personalizado) return [$this->error('CUSTOM_COURSE_NOT_SUPPORTED', 'Los cursos personalizados no están soportados en la importación financiera.')];
        return [];
    }

    public function validateModules(array $modules, CursoAbierto $course, array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $courseModules = Modulo::query()->where('curso_abierto_id', $course->id)->get()->keyBy('id');
        $seen = [];

        foreach ($modules as $module) {
            $moduleId = (string) ($module['modulo_id'] ?? '');
            if (!$moduleId || !$courseModules->has($moduleId)) {
                $errors[] = $this->error('MODULE_MAPPING_INVALID', 'El módulo no existe o no pertenece al curso seleccionado.');
                continue;
            }
            if (isset($seen[$moduleId])) {
                $errors[] = $this->error('MODULE_MAPPING_DUPLICATE', 'El mismo módulo fue asignado a más de un grupo Excel.');
                continue;
            }
            $seen[$moduleId] = true;

            foreach (['total', 'paid', 'balance'] as $field) {
                $value = $module[$field] ?? null;
                if (!is_numeric($value) || (float) $value < 0) {
                    $errors[] = $this->error('FINANCIAL_AMOUNT_INVALID', "El campo {$field} debe ser un número mayor o igual a cero.");
                }
            }

            $total = (float) ($module['total'] ?? 0);
            $paid = (float) ($module['paid'] ?? 0);
            $balance = (float) ($module['balance'] ?? 0);
            if ($paid > $total + 0.01) {
                $errors[] = $this->error('FINANCIAL_AMOUNT_INVALID', 'El abono no puede superar el total.');
            }
            if (abs(($total - $paid) - $balance) > 0.01) {
                $errors[] = $this->error('FINANCIAL_BALANCE_MISMATCH', 'TOTAL - ABONO no coincide con SALDO.');
            }

            if ($total <= 0 && $paid <= 0 && $balance <= 0) {
                $warnings[] = $this->warning('NO_FINANCIAL_VALUE', 'El módulo no generará estructuras financieras porque su total es cero.');
            }

            $currentPrice = $courseModules[$moduleId]->precio_base;
            if ($total > 0 && $currentPrice !== null && abs((float) $currentPrice - $total) > 0.01) {
                $warnings[] = $this->warning('HISTORICAL_PRICE_DIFFERENCE', 'El valor histórico difiere del precio actual del módulo.');
                if (!filter_var($options['payment_options']['confirm_price_differences'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $errors[] = $this->error('HISTORICAL_PRICE_CONFIRMATION_REQUIRED', 'Debe confirmar las diferencias de precio histórico.');
                }
            }
        }

        return compact('errors', 'warnings');
    }

    public function parseAmount(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') return 0.0;
        $value = trim((string) $value);
        $value = str_replace(['$', ' ', "\u{00A0}"], '', $value);
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function error(string $code, string $message): array
    {
        return compact('code', 'message');
    }

    private function warning(string $code, string $message): array
    {
        return compact('code', 'message');
    }
}
