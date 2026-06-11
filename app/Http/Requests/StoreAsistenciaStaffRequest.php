<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAsistenciaStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'persona_id' => 'required|uuid|exists:personas,id',
            'fecha' => 'required|date',
            'hora_entrada' => 'nullable|date_format:H:i',
            'hora_salida' => 'nullable|date_format:H:i',
            'actividades' => 'nullable|string',
            'observaciones' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'persona_id.required' => 'La persona es obligatoria',
            'fecha.required' => 'La fecha es obligatoria',
        ];
    }
}
