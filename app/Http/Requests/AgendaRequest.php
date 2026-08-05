<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_inicio' => 'nullable|date|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'tipo' => 'nullable|string',
            'tipos' => 'nullable|array',
            'tipos.*' => 'string|in:CLASE_CURSO,TALLER,ALQUILER_AULA,PODCAST,STREAMING,ASESORIA,RADIO',
            'per_page' => 'nullable|integer|min:1|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_inicio.date_format' => 'La fecha de inicio debe tener formato Y-m-d',
            'fecha_fin.date_format' => 'La fecha de fin debe tener formato Y-m-d',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
            'tipos.*.in' => 'El tipo de evento no es válido',
        ];
    }
}
