<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParticipanteExternoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'institucion' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', 'in:persona_externa,profesional,estudiante_externo'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio',
            'email.email' => 'El email debe ser válido',
            'tipo.required' => 'El tipo de participante es obligatorio',
        ];
    }
}
