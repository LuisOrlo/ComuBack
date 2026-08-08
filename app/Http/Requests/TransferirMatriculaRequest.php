<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferirMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'curso_abierto_nuevo_id' => ['required', 'string', 'uuid', 'exists:pgsql.academic.cursos_abiertos,id'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'lineas' => ['nullable', 'array', 'min:1'],
            'lineas.*.modulo_id' => ['nullable', 'uuid', 'exists:pgsql.academic.modulos,id'],
            'lineas.*.tipo' => ['required', 'string', 'in:modulo,inscripcion'],
            'lineas.*.monto_abonado' => ['required', 'numeric', 'min:0'],
            'lineas.*.monto_ajustado' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'curso_abierto_nuevo_id.required' => 'Debe seleccionar un curso destino.',
            'curso_abierto_nuevo_id.uuid' => 'El curso destino no es válido.',
            'curso_abierto_nuevo_id.exists' => 'El curso destino no existe.',
            'motivo.max' => 'El motivo no puede exceder 500 caracteres.',
        ];
    }
}
