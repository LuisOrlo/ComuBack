<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'programa_id' => 'nullable|uuid',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'creditos' => 'nullable|integer|min:1|max:10',
            'horas_totales' => 'nullable|integer|min:1|max:200',
            'modulos_default' => 'nullable|integer|min:0|max:10',
            'es_activo' => 'boolean',
            'categoria' => 'nullable|in:regular,personalizado,taller',
            'imagen' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio',
        ];
    }

    protected function prepareForValidation(): void
    {
        $categoria = $this->categoria ?? 'regular';

        $this->merge([
            'es_activo' => $this->es_activo ?? true,
            'categoria' => $categoria,
            'modulos_default' => $this->modulos_default ?? ($categoria === 'taller' ? 0 : 2),
        ]);
    }
}
