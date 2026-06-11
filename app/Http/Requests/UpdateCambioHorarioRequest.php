<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCambioHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => 'sometimes|required|in:pendiente,aprobado,rechazado,completado',
            'observaciones_admin' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser: pendiente, aprobado, rechazado o completado',
        ];
    }
}
