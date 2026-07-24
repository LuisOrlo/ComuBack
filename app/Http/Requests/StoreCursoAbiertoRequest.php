<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCursoAbiertoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catalogo_curso_id' => 'required|uuid|exists:catalogo_cursos,id',
            'nombre_instancia' => 'required|string|max:255',
            'semestre' => 'nullable|string|max:50|regex:/^\d{4}-[1-2]$/',
            'fecha_inicio' => 'required|date|date_format:Y-m-d',
            'fecha_fin' => 'required|date|date_format:Y-m-d|after:fecha_inicio',
            'capacidad_maxima' => 'required|integer|min:1|max:100',
            'docente_id' => 'nullable|uuid|exists:personas,id',
            'es_activo' => 'boolean',
            'observaciones' => 'nullable|string|max:1000',
            'modalidad' => 'nullable|in:presencial,virtual',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'precio_base' => 'nullable|numeric|min:0',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'modulos' => 'nullable|array|max:10',
            'modulos.*.nombre' => 'nullable|string|max:100',
            'modulos.*.fecha_inicio' => 'nullable|date',
            'modulos.*.fecha_fin' => 'nullable|date',
            'dias_semana' => 'nullable|array|min:1|max:7',
            'dias_semana.*' => 'integer|min:1|max:7',
        ];
    }

    public function messages(): array
    {
        return [
            'catalogo_curso_id.required' => 'El catálogo de curso es obligatorio',
            'catalogo_curso_id.exists' => 'El catálogo no existe',
            'nombre_instancia.required' => 'El nombre de la instancia es obligatorio',
            'semestre.required' => 'El semestre es obligatorio',
            'semestre.regex' => 'El semestre debe estar en formato: YYYY-[1|2] (ej: 2026-1)',
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria',
            'fecha_fin.required' => 'La fecha de fin es obligatoria',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
            'capacidad_maxima.required' => 'La capacidad máxima es obligatoria',
            'capacidad_maxima.min' => 'La capacidad mínima es 1 estudiante',
            'docente_id.exists' => 'El docente no existe',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'es_activo' => $this->es_activo ?? true,
        ]);
    }
}
