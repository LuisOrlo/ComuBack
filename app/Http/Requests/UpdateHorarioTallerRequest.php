<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHorarioTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dia_semana' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'hora_inicio' => ['sometimes', 'date_format:H:i:s'],
            'hora_fin' => ['sometimes', 'date_format:H:i:s'],
            'aula' => ['nullable', 'string', 'max:50'],
            'capacidad' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'dia_semana.min' => 'El día debe estar entre 1 (Lunes) y 7 (Domingo)',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
        ];
    }
}
