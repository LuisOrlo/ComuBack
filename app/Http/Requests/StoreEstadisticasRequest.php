<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstadisticasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'unidad_negocio' => ['nullable', 'string', 'in:todos,academia,servicios'],
        ];
    }

    public function messages(): array
    {
        return [
            'desde.date' => 'La fecha "desde" debe ser una fecha válida.',
            'hasta.date' => 'La fecha "hasta" debe ser una fecha válida.',
            'hasta.after_or_equal' => 'La fecha "hasta" debe ser igual o posterior a "desde".',
        ];
    }
}
