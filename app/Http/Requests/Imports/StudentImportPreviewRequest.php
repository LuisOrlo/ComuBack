<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class StudentImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['mapping', 'enrollment', 'finance'] as $field) {
            if (is_string($this->input($field))) {
                $decoded = json_decode($this->input($field), true);
                if (is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required_without:preview_id', 'nullable', 'file', 'mimes:csv,xls,xlsx', 'max:10240'],
            'preview_id' => ['nullable', 'uuid'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['string', 'max:100'],
            'enrollment' => ['nullable', 'array'],
            'enrollment.enabled' => ['nullable', 'boolean'],
            'enrollment.curso_abierto_id' => ['nullable', 'uuid'],
            'enrollment.sin_registro_financiero' => ['nullable', 'boolean'],
            'finance' => ['nullable', 'array'],
            'finance.enabled' => ['nullable', 'boolean'],
            'finance.confirm_real_financial_impact' => ['nullable', 'boolean'],
            'finance.groups' => ['nullable', 'array'],
            'finance.groups.*' => ['array'],
            'finance.groups.*.external_group_key' => ['required_with:finance.groups', 'string', 'max:100'],
            'finance.groups.*.external_group_name' => ['nullable', 'string', 'max:150'],
            'finance.groups.*.total_column' => ['required_with:finance.groups', 'string', 'max:150'],
            'finance.groups.*.paid_column' => ['required_with:finance.groups', 'string', 'max:150'],
            'finance.groups.*.balance_column' => ['required_with:finance.groups', 'string', 'max:150'],
            'finance.groups.*.modulo_id' => ['required_with:finance.groups', 'uuid'],
            'finance.payment_options' => ['nullable', 'array'],
            'finance.payment_options.fecha_pago_default' => ['nullable', 'date'],
            'finance.payment_options.metodo_pago' => ['nullable', 'in:efectivo,transferencia,deposito,tarjeta,otro'],
            'finance.payment_options.comprobante_url' => ['nullable', 'string'],
            'finance.payment_options.observaciones' => ['nullable', 'string'],
            'finance.payment_options.confirm_price_differences' => ['nullable', 'boolean'],
        ];
    }
}
