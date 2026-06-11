<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRegisterNotasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'notas' => 'required|array|min:1|max:1000',
            'notas.*.matricula_id' => 'required|uuid|exists:matriculas,id',
            'notas.*.modulo_id' => 'required|uuid|exists:modulos,id',
            'notas.*.calificacion' => 'required|numeric|min:0|max:5',
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
            'notas.*.matricula_id.required' => 'ID de matrícula requerido',
            'notas.*.matricula_id.uuid' => 'ID de matrícula inválido',
            'notas.*.matricula_id.exists' => 'Matrícula no encontrada',
            'notas.*.modulo_id.required' => 'ID de módulo requerido',
            'notas.*.modulo_id.uuid' => 'ID de módulo inválido',
            'notas.*.modulo_id.exists' => 'Módulo no encontrado',
            'notas.*.calificacion.required' => 'Calificación requerida',
            'notas.*.calificacion.numeric' => 'Calificación debe ser numérica',
            'notas.*.calificacion.min' => 'Calificación mínima es 0',
            'notas.*.calificacion.max' => 'Calificación máxima es 5',
        ];
    }
}
