<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'programa_id' => 'nullable|uuid',
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'creditos' => 'nullable|integer|min:1|max:10',
            'horas_totales' => 'nullable|integer|min:1|max:200',
            'modulos_default' => 'nullable|integer|min:1|max:10',
            'es_activo' => 'boolean',
            'categoria' => 'nullable|in:regular,personalizado,taller',
            'imagen' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [];
    }
}
