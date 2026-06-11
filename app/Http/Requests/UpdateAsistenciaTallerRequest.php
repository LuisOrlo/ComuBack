<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAsistenciaTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asistentes' => ['sometimes', 'integer', 'min:0'],
            'capacidad_registrada' => ['sometimes', 'integer', 'min:1'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'asistentes.min' => 'El número de asistentes no puede ser negativo',
            'capacidad_registrada.min' => 'La capacidad debe ser mínimo 1',
        ];
    }
}
