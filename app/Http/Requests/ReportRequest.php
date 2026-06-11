<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'tipo_reporte' => 'required|in:asistencia,desempeño,progreso,resumen_academico',
            'curso_abierto_id' => 'nullable|uuid|exists:cursos_abiertos,id',
            'estudiante_id' => 'nullable|uuid|exists:users,id',
            'formato' => 'nullable|in:json,csv,pdf',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after:fecha_inicio',
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_reporte.required' => 'El tipo de reporte es requerido',
            'tipo_reporte.in' => 'Tipo de reporte inválido',
            'curso_abierto_id.uuid' => 'ID de curso inválido',
            'curso_abierto_id.exists' => 'Curso no encontrado',
            'estudiante_id.uuid' => 'ID de estudiante inválido',
            'estudiante_id.exists' => 'Estudiante no encontrado',
            'formato.in' => 'Formato debe ser: json, csv o pdf',
            'fecha_inicio.date' => 'Fecha inicio debe ser una fecha válida',
            'fecha_fin.date' => 'Fecha fin debe ser una fecha válida',
            'fecha_fin.after' => 'Fecha fin debe ser posterior a fecha inicio',
        ];
    }
}
