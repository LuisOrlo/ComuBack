<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModuloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'semana_inicio' => 'sometimes|required|integer|min:1|max:52',
            'semana_fin' => 'sometimes|required|integer|min:1|max:52|gte:semana_inicio',
            'ponderacion' => 'sometimes|required|numeric|min:0.1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'semana_fin.gte' => 'La semana de fin debe ser mayor o igual a la de inicio',
        ];
    }
}
