<?php

namespace App\Http\Requests\Estudiantes;

use Illuminate\Foundation\Http\FormRequest;

class EstudianteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // La cédula se valida contra Persona dentro de la transacción del
            // controlador para poder reutilizar una Persona estudiante sin perfil.
            'cedula' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'nombres' => ['required', 'string', 'max:100', 'regex:/^[\pL]+(?:[ \'-][\pL]+)*$/u'],
            'apellidos' => ['required', 'string', 'max:100', 'regex:/^[\pL]+(?:[ \'-][\pL]+)*$/u'],
            'correo' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'ciudad_id' => ['nullable', 'exists:pgsql.core.ciudades,id'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'archivo_cedula' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
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
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'correo.email' => 'El correo debe ser una dirección válida.',
            'cedula.regex' => 'La cédula debe contener exactamente 10 dígitos.',
            'celular.regex' => 'El celular debe contener exactamente 10 dígitos.',
            'nombres.regex' => 'Los nombres solo pueden contener letras, espacios, guiones o apóstrofes.',
            'apellidos.regex' => 'Los apellidos solo pueden contener letras, espacios, guiones o apóstrofes.',
            'ciudad_id.exists' => 'La ciudad seleccionada no existe.',
        ];
    }
}
