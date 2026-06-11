<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'matriculas' => 'required|array|min:1|max:1000',
            'matriculas.*.id' => 'required|uuid|exists:matriculas,id',
            'matriculas.*.nuevo_estado' => 'required|in:activo,completado,retirado,reprobado',
        ];
    }

    public function messages(): array
    {
        return [
            'matriculas.required' => 'El campo matriculas es requerido',
            'matriculas.array' => 'Las matrículas deben ser un array',
            'matriculas.min' => 'Se requiere al menos 1 matrícula',
            'matriculas.max' => 'Máximo 1000 matrículas por solicitud',
            'matriculas.*.id.required' => 'ID de matrícula requerido',
            'matriculas.*.id.uuid' => 'ID de matrícula inválido',
            'matriculas.*.id.exists' => 'Matrícula no encontrada',
            'matriculas.*.nuevo_estado.required' => 'Nuevo estado requerido',
            'matriculas.*.nuevo_estado.in' => 'Estado inválido',
        ];
    }
}
