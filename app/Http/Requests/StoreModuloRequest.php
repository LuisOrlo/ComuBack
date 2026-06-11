<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreModuloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'semana_inicio' => 'required|integer|min:1|max:52',
            'semana_fin' => 'required|integer|min:1|max:52|gte:semana_inicio',
            'ponderacion' => 'required|numeric|min:0.1|max:100',
            'catalogo_curso_id' => 'required_without:curso_abierto_id|nullable|uuid|exists:catalogo_cursos,id',
            'curso_abierto_id' => 'required_without:catalogo_curso_id|nullable|uuid|exists:cursos_abiertos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del módulo es obligatorio',
            'semana_inicio.required' => 'La semana de inicio es obligatoria',
            'semana_inicio.min' => 'La semana debe ser mínimo 1',
            'semana_fin.required' => 'La semana de fin es obligatoria',
            'semana_fin.gte' => 'La semana de fin debe ser mayor o igual a la de inicio',
            'ponderacion.required' => 'La ponderación es obligatoria',
            'ponderacion.min' => 'La ponderación debe ser mínimo 0.1%',
            'ponderacion.max' => 'La ponderación no puede exceder 100%',
            'catalogo_curso_id.required_without' => 'Debe especificar un catálogo o un curso',
            'curso_abierto_id.required_without' => 'Debe especificar un catálogo o un curso',
        ];
    }
}
