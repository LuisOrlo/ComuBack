<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => 'required|in:instructor,staff,secretaria,admin',
            'cedula' => 'nullable|string|max:20|unique:personas,cedula',
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'correo' => 'nullable|email|max:150',
            'celular' => 'nullable|string|max:20',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'es_activo' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'El tipo de persona es obligatorio',
            'nombres.required' => 'Los nombres son obligatorios',
            'apellidos.required' => 'Los apellidos son obligatorios',
            'cedula.unique' => 'La cédula ya está registrada',
        ];
    }
}
