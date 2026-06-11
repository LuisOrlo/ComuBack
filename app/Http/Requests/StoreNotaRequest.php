<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricula_id' => 'required|uuid|exists:matriculas,id',
            'modulo_id' => 'required|uuid|exists:modulos,id',
            'calificacion' => 'required|numeric|min:0|max:5|regex:/^\d+(\.\d{1,2})?$/',
            'observaciones' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_id.required' => 'La matrícula es obligatoria',
            'matricula_id.exists' => 'La matrícula no existe',
            'modulo_id.required' => 'El módulo es obligatorio',
            'modulo_id.exists' => 'El módulo no existe',
            'calificacion.required' => 'La calificación es obligatoria',
            'calificacion.min' => 'La calificación mínima es 0',
            'calificacion.max' => 'La calificación máxima es 5',
            'calificacion.regex' => 'La calificación debe tener máximo 2 decimales',
        ];
    }
}
