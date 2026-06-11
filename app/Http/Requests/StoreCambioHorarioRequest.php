<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCambioHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricula_origen_id' => 'required|uuid|exists:matriculas,id',
            'curso_abierto_antiguo_id' => 'required|uuid|exists:cursos_abiertos,id',
            'curso_abierto_nuevo_id' => 'required|uuid|exists:cursos_abiertos,id|different:curso_abierto_antiguo_id',
            'motivo' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_origen_id.required' => 'La matrícula es obligatoria',
            'matricula_origen_id.exists' => 'La matrícula no existe',
            'curso_abierto_antiguo_id.required' => 'El curso actual es obligatorio',
            'curso_abierto_antiguo_id.exists' => 'El curso actual no existe',
            'curso_abierto_nuevo_id.required' => 'El nuevo curso es obligatorio',
            'curso_abierto_nuevo_id.exists' => 'El nuevo curso no existe',
            'curso_abierto_nuevo_id.different' => 'El nuevo curso debe ser diferente al actual',
            'motivo.required' => 'El motivo es obligatorio',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => 'pendiente',
        ]);
    }
}
