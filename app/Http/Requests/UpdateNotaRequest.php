<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calificacion' => 'sometimes|required|numeric|min:0|max:5|regex:/^\d+(\.\d{1,2})?$/',
            'observaciones' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'calificacion.min' => 'La calificación mínima es 0',
            'calificacion.max' => 'La calificación máxima es 5',
            'calificacion.regex' => 'La calificación debe tener máximo 2 decimales',
        ];
    }
}
