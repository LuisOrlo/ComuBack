<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estudiante_id' => 'required|uuid|exists:personas,id',
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
            'horario_id' => 'required|uuid|exists:horarios,id',
            'estado' => 'required|in:activo,completado,retirado,reprobado',
            'fecha_inicio' => 'required|date|date_format:Y-m-d',
            'fecha_fin' => 'required|date|date_format:Y-m-d|after:fecha_inicio',
            'observaciones' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'estudiante_id.required' => 'El estudiante es obligatorio',
            'estudiante_id.exists' => 'El estudiante no existe',
            'curso_abierto_id.required' => 'El curso es obligatorio',
            'curso_abierto_id.exists' => 'El curso no existe',
            'horario_id.required' => 'El horario es obligatorio',
            'horario_id.exists' => 'El horario no existe',
            'estado.required' => 'El estado es obligatorio',
            'estado.in' => 'El estado debe ser: activo, completado, retirado o reprobado',
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => $this->estado ?? 'activo',
        ]);
    }
}
