<?php

namespace App\Http\Requests\Estudiantes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstudianteUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $estudianteId = $this->route('estudiante');

        return [
            'cedula' => ['nullable', 'string', 'regex:/^\d{10}$/', Rule::unique('pgsql.people.personas', 'cedula')->ignore($estudianteId)],
            'nombres' => ['sometimes', 'string', 'max:100', 'regex:/^[\pL]+(?:[ \'-][\pL]+)*$/u'],
            'apellidos' => ['sometimes', 'string', 'max:100', 'regex:/^[\pL]+(?:[ \'-][\pL]+)*$/u'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'ciudad_id' => ['nullable', 'exists:pgsql.core.ciudades,id'],
            'notas_internas' => ['nullable', 'string'],
            'es_activo' => ['sometimes', 'boolean'],
            'ocupacion' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:1000'],
            'estado_civil' => ['nullable', 'string', 'max:50'],
            'edad' => ['nullable', 'integer', 'min:0', 'max:150'],
            'nivel_educativo' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'cedula.unique' => 'La cédula ya se encuentra registrada.',
            'correo.email' => 'El correo debe ser una dirección válida.',
            'nombres.regex' => 'Los nombres solo pueden contener letras, espacios, guiones o apóstrofes.',
            'apellidos.regex' => 'Los apellidos solo pueden contener letras, espacios, guiones o apóstrofes.',
            'cedula.regex' => 'La cédula debe contener exactamente 10 dígitos.',
            'celular.regex' => 'El celular debe contener exactamente 10 dígitos.',
            'ciudad_id.exists' => 'La ciudad seleccionada no existe.',
            'es_activo.boolean' => 'El estado debe ser verdadero o falso.',
        ];
    }
}
