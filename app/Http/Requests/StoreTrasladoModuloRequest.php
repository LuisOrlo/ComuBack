<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrasladoModuloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricula_origen_id' => 'required|uuid|exists:matriculas,id',
            'modulo_antiguo_id' => 'required|uuid|exists:modulos,id',
            'modulo_nuevo_id' => 'required|uuid|exists:modulos,id|different:modulo_antiguo_id',
            'motivo' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_origen_id.required' => 'La matrícula es obligatoria',
            'matricula_origen_id.exists' => 'La matrícula no existe',
            'modulo_antiguo_id.required' => 'El módulo actual es obligatorio',
            'modulo_antiguo_id.exists' => 'El módulo actual no existe',
            'modulo_nuevo_id.required' => 'El nuevo módulo es obligatorio',
            'modulo_nuevo_id.exists' => 'El nuevo módulo no existe',
            'modulo_nuevo_id.different' => 'El nuevo módulo debe ser diferente al actual',
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
