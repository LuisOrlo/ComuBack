<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombres' => ['sometimes', 'string', 'max:100'],
            'apellidos' => ['sometimes', 'string', 'max:100'],
            'correo' => ['sometimes', 'email', 'max:150'],
            'celular' => ['sometimes', 'string', 'max:20'],
            'cedula' => ['sometimes', 'string', 'max:20', Rule::unique('personas', 'cedula')->ignore($this->user()?->persona_id)],
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.string' => 'Los nombres deben ser una cadena de texto.',
            'nombres.max' => 'Los nombres no deben exceder los 100 caracteres.',
            'apellidos.string' => 'Los apellidos deben ser una cadena de texto.',
            'apellidos.max' => 'Los apellidos no deben exceder los 100 caracteres.',
            'correo.email' => 'El correo debe ser una dirección de correo válida.',
            'correo.max' => 'El correo no debe exceder los 150 caracteres.',
            'celular.string' => 'El celular debe ser una cadena de texto.',
            'celular.max' => 'El celular no debe exceder los 20 caracteres.',
            'cedula.string' => 'La cédula debe ser una cadena de texto.',
            'cedula.max' => 'La cédula no debe exceder los 20 caracteres.',
            'cedula.unique' => 'La cédula ya se encuentra registrada.',
        ];
    }
}
