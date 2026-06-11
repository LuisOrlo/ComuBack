<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalizadoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catalogo_curso_id' => ['required', 'uuid', 'exists:catalogo_cursos,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'fecha_inicio' => ['required', 'date', 'after_or_equal:today'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:500'],
            'instructor_id' => ['required', 'uuid', 'exists:users,id'],
            'dirigido_a' => ['nullable', 'string', 'max:500'],
            'requisitos_especiales' => ['nullable', 'string', 'max:500'],
            'certificado_emitido' => ['required', 'boolean'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'acepta_externos' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'catalogo_curso_id.required' => 'El catálogo de curso es obligatorio',
            'catalogo_curso_id.exists' => 'El catálogo no existe',
            'nombre.required' => 'El nombre es obligatorio',
            'fecha_inicio.after_or_equal' => 'La fecha de inicio no puede ser en el pasado',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
            'instructor_id.required' => 'El instructor es obligatorio',
            'instructor_id.exists' => 'El instructor no existe',
        ];
    }
}
