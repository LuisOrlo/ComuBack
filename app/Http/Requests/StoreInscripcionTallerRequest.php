<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInscripcionTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'taller_id' => ['required', 'uuid', 'exists:talleres,id'],
            'estudiante_id' => ['required', 'uuid', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'taller_id.required' => 'El taller es obligatorio',
            'taller_id.exists' => 'El taller no existe',
            'estudiante_id.required' => 'El estudiante es obligatorio',
            'estudiante_id.exists' => 'El estudiante no existe',
        ];
    }
}
