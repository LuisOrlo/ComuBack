<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $personaId = $this->route('id');

        return [
            'tipo' => 'sometimes|in:instructor,staff,secretaria,admin',
            'cedula' => ['nullable', 'string', 'max:20', Rule::unique('personas', 'cedula')->ignore($personaId)],
            'nombres' => 'sometimes|required|string|max:100',
            'apellidos' => 'sometimes|required|string|max:100',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'es_activo' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'cedula.unique' => 'La cédula ya está registrada',
        ];
    }
}
