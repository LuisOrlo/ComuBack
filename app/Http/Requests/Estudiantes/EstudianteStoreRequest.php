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
            'cedula' => ['nullable', 'string', 'max:20', Rule::unique('pgsql.people.personas', 'cedula')],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'ciudad_id' => ['nullable', 'exists:pgsql.core.ciudades,id'],
            'notas_internas' => ['nullable', 'string'],
            'ocupacion' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:1000'],
            'estado_civil' => ['nullable', 'string', 'max:20'],
            'edad' => ['nullable', 'integer', 'min:0', 'max:150'],
            'nivel_educativo' => ['nullable', 'string', 'in:educacion inicial,general basica,bachillerato,tecnico/tecnologico,superior,otro'],
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
        ];
    }
}
