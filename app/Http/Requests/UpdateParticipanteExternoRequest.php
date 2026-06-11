<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateParticipanteExternoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'institucion' => ['nullable', 'string', 'max:255'],
            'tipo' => ['sometimes', 'in:persona_externa,profesional,estudiante_externo'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'El email debe ser válido',
        ];
    }
}
