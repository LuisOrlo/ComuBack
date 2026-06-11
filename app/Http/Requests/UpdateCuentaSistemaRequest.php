<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCuentaSistemaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cuentaId = $this->route('id');

        return [
            'username' => 'sometimes|string|max:100|unique:cuentas_sistema,username,' . $cuentaId . ',persona_id',
            'password' => 'nullable|string|min:6|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'El nombre de usuario ya está en uso',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',
        ];
    }
}
