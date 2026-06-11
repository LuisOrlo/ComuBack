<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_referencial' => 'sometimes|required|string|max:255',
            'hora_inicio' => 'sometimes|required|date_format:H:i',
            'hora_fin' => 'sometimes|required|date_format:H:i|after:hora_inicio',
            'dias_semana' => 'sometimes|required|array|min:1|max:7',
            'dias_semana.*' => 'integer|min:1|max:7|distinct',
            'es_activo' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio',
            'dias_semana.*.distinct' => 'No puede haber días duplicados',
        ];
    }
}
