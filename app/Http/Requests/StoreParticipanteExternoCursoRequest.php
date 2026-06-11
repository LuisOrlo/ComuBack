<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParticipanteExternoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'curso_personalizado_id' => ['required', 'uuid', 'exists:cursos_abiertos,id'],
            'participante_externo_id' => ['required', 'uuid', 'exists:participantes_externos,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'curso_personalizado_id.required' => 'El curso es obligatorio',
            'curso_personalizado_id.exists' => 'El curso no existe',
            'participante_externo_id.required' => 'El participante es obligatorio',
            'participante_externo_id.exists' => 'El participante no existe',
        ];
    }
}
