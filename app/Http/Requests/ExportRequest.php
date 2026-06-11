<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'format' => 'required|in:csv,pdf,excel',
            'tipo_datos' => 'required|in:calificaciones,asistencia,horarios,todos',
            'curso_abierto_id' => 'nullable|uuid|exists:cursos_abiertos,id',
            'filtro_estado' => 'nullable|in:activo,completado,retirado,reprobado',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after:fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            'format.required' => 'El formato es requerido',
            'format.in' => 'Formato debe ser: csv, pdf o excel',
            'tipo_datos.required' => 'El tipo de datos es requerido',
            'tipo_datos.in' => 'Tipo de datos inválido',
            'curso_abierto_id.uuid' => 'ID de curso inválido',
            'curso_abierto_id.exists' => 'Curso no encontrado',
            'filtro_estado.in' => 'Estado inválido',
            'fecha_inicio.date' => 'Fecha inicio debe ser una fecha válida',
            'fecha_fin.date' => 'Fecha fin debe ser una fecha válida',
            'fecha_fin.after' => 'Fecha fin debe ser posterior a fecha inicio',
        ];
    }
}
