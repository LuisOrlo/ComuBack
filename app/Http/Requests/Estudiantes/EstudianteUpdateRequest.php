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
            'cedula' => ['nullable', 'string', 'max:20', Rule::unique('pgsql.people.personas', 'cedula')->ignore($estudianteId)],
            'nombres' => ['sometimes', 'string', 'max:100'],
            'apellidos' => ['sometimes', 'string', 'max:100'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'ciudad_id' => ['nullable', 'exists:pgsql.core.ciudades,id'],
            'notas_internas' => ['nullable', 'string'],
            'es_activo' => ['sometimes', 'boolean'],
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
            'correo.email' => 'El correo debe ser una dirección válida.',
            'ciudad_id.exists' => 'La ciudad seleccionada no existe.',
            'es_activo.boolean' => 'El estado debe ser verdadero o falso.',
        ];
    }
}
