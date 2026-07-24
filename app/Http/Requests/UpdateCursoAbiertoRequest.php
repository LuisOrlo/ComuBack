<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCursoAbiertoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_instancia' => 'sometimes|required|string|max:255',
            'semestre' => 'sometimes|required|string|max:50|regex:/^\d{4}-[1-2]$/',
            'fecha_inicio' => 'sometimes|required|date|date_format:Y-m-d',
            'fecha_fin' => 'sometimes|required|date|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'capacidad_maxima' => 'sometimes|required|integer|min:1|max:100',
            'docente_id' => 'sometimes|nullable|uuid|exists:personas,id',
            'es_activo' => 'boolean',
            'observaciones' => 'nullable|string|max:1000',
            'modalidad' => 'nullable|in:presencial,virtual',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'precio_base' => 'nullable|numeric|min:0',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'dias_semana' => 'nullable|array|min:1|max:7',
            'dias_semana.*' => 'integer|min:1|max:7',
        ];
    }

    public function messages(): array
    {
        return [
            'semestre.regex' => 'El semestre debe estar en formato: YYYY-[1|2]',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la de inicio',
            'docente_id.exists' => 'El docente no existe',
        ];
    }
}
