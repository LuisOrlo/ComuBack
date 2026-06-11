<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estudiante_id' => [
                'uuid',
                function ($attribute, $value, $fail) {
                    $exists = \Illuminate\Support\Facades\DB::table('people.personas')->where('id', $value)->exists()
                        || \Illuminate\Support\Facades\DB::table('people.clientes_externos')->where('id', $value)->exists();
                    if (!$exists) {
                        $fail('El estudiante o participante externo no existe.');
                    }
                },
            ],
            'matricula_id' => 'uuid|exists:pgsql.academic.matriculas,id',
            'catalogo_id' => 'nullable|uuid|exists:pgsql.academic.catalogo_cursos,id',
            'curso_abierto_id' => 'nullable|uuid|exists:pgsql.academic.cursos_abiertos,id',
            'modulo_id' => 'nullable|uuid|exists:pgsql.academic.modulos,id',
            'fecha_emision' => 'nullable|date|date_format:Y-m-d',
            'pdf' => 'required|file|mimes:pdf|max:512',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            $hasEstudiante = !empty($data['estudiante_id']);
            $hasMatricula = !empty($data['matricula_id']);

            if (!$hasEstudiante && !$hasMatricula) {
                $validator->errors()->add('estudiante_id', 'Debe proporcionar estudiante_id o matricula_id.');
            }

            if ($hasEstudiante && $hasMatricula) {
                $validator->errors()->add('estudiante_id', 'No puede proporcionar ambos: estudiante_id y matricula_id.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'estudiante_id.required' => 'El estudiante es obligatorio',
            'estudiante_id.exists' => 'El estudiante no existe',
            'catalogo_id.required' => 'El catálogo de curso es obligatorio',
            'catalogo_id.exists' => 'El catálogo de curso no existe',
            'curso_abierto_id.exists' => 'El curso abierto no existe',
            'modulo_id.exists' => 'El módulo no existe',
            'pdf.required' => 'El archivo PDF es obligatorio',
            'pdf.file' => 'Debe subir un archivo',
            'pdf.mimes' => 'El archivo debe ser PDF',
            'pdf.max' => 'El PDF no debe superar los 500 KB',
        ];
    }
}
