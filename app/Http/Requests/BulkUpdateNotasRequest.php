<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateNotasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'notas' => 'required|array|min:1|max:1000',
            'notas.*.id' => 'required|uuid|exists:notas,id',
            'notas.*.calificacion' => 'nullable|numeric|min:0|max:5',
            'notas.*.observaciones' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'notas.required' => 'El campo notas es requerido',
            'notas.array' => 'Las notas deben ser un array',
            'notas.min' => 'Se requiere al menos 1 nota',
            'notas.max' => 'Máximo 1000 notas por solicitud',
            'notas.*.id.required' => 'ID de nota requerido',
            'notas.*.id.uuid' => 'ID de nota inválido',
            'notas.*.id.exists' => 'Nota no encontrada',
            'notas.*.calificacion.numeric' => 'Calificación debe ser numérica',
            'notas.*.calificacion.min' => 'Calificación mínima es 0',
            'notas.*.calificacion.max' => 'Calificación máxima es 5',
        ];
    }
}
