<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => 'sometimes|required|in:activo,completado,retirado,reprobado',
            'fecha_fin' => 'sometimes|required|date|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'observaciones' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser: activo, completado, retirado o reprobado',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la de inicio',
        ];
    }
}
