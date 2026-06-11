<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHorarioTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'taller_id' => ['required', 'uuid', 'exists:talleres,id'],
            'dia_semana' => ['required', 'integer', 'min:1', 'max:7'],
            'hora_inicio' => ['required', 'date_format:H:i:s'],
            'hora_fin' => ['required', 'date_format:H:i:s'],
            'aula' => ['nullable', 'string', 'max:50'],
            'capacidad' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'taller_id.required' => 'El taller es obligatorio',
            'taller_id.exists' => 'El taller no existe',
            'dia_semana.required' => 'El día de la semana es obligatorio',
            'dia_semana.min' => 'El día debe estar entre 1 (Lunes) y 7 (Domingo)',
            'hora_inicio.required' => 'La hora de inicio es obligatoria',
            'hora_fin.required' => 'La hora de fin es obligatoria',
            'capacidad.required' => 'La capacidad es obligatoria',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
        ];
    }
}
