<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAsistenciaTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'taller_id' => ['required', 'uuid', 'exists:talleres,id'],
            'fecha_sesion' => ['required', 'date'],
            'asistentes' => ['required', 'integer', 'min:0'],
            'capacidad_registrada' => ['required', 'integer', 'min:1'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'taller_id.required' => 'El taller es obligatorio',
            'taller_id.exists' => 'El taller no existe',
            'fecha_sesion.required' => 'La fecha de la sesión es obligatoria',
            'asistentes.required' => 'El número de asistentes es obligatorio',
            'asistentes.min' => 'El número de asistentes no puede ser negativo',
            'capacidad_registrada.required' => 'La capacidad registrada es obligatoria',
            'capacidad_registrada.min' => 'La capacidad debe ser mínimo 1',
        ];
    }
}
