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
            // La cédula se normaliza y se compara contra Persona dentro de la
            // transacción del controlador. El frontend es responsable de las
            // reglas de formato y experiencia del formulario.
            'cedula' => ['nullable', 'string'],
            'nombres' => ['nullable'],
            'apellidos' => ['nullable'],
            'correo' => ['nullable'],
            'celular' => ['nullable'],
            'ciudad_id' => ['nullable'],
            'ciudad' => ['nullable'],
            'archivo_cedula' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,webp,pdf',
                function ($attribute, $value, $fail) {
                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        $isPdf = strtolower($value->getClientOriginalExtension()) === 'pdf' || $value->getMimeType() === 'application/pdf';
                        if ($isPdf && $value->getSize() > 5 * 1024 * 1024) {
                            $fail('El documento PDF de la cédula no debe superar los 5 MB.');
                        }
                    }
                },
            ],
            'notas_internas' => ['nullable'],
            'ocupacion' => ['nullable'],
            'direccion' => ['nullable'],
            'estado_civil' => ['nullable'],
            'edad' => ['nullable'],
            'nivel_educativo' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'cedula.string' => 'La cédula debe recibirse como texto.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cedula') && is_string($this->input('cedula'))) {
            $cedula = trim($this->input('cedula'));
            $cedula = preg_replace('/[\s-]+/u', '', $cedula) ?? $cedula;

            $this->merge(['cedula' => $cedula !== '' ? $cedula : null]);
        }
    }
}
