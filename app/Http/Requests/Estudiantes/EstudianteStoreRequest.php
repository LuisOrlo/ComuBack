<?php

namespace App\Http\Requests\Estudiantes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstudianteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cedula' => ['nullable', 'string', 'max:20', Rule::unique('personas', 'cedula')],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'ciudad_id' => ['nullable', 'exists:ciudades,id'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'notas_internas' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'cedula.unique' => 'La cédula ya se encuentra registrada.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'correo.email' => 'El correo debe ser una dirección válida.',
            'ciudad_id.exists' => 'La ciudad seleccionada no existe.',
            'fecha_nacimiento.date' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
        ];
    }
}
