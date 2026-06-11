<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
            'nombre_referencial' => 'required|string|max:255',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'dias_semana' => 'required|array|min:1|max:7',
            'dias_semana.*' => 'integer|min:1|max:7|distinct',
            'es_activo' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'curso_abierto_id.required' => 'El curso es obligatorio',
            'curso_abierto_id.exists' => 'El curso no existe',
            'nombre_referencial.required' => 'El nombre referencial es obligatorio',
            'hora_inicio.required' => 'La hora de inicio es obligatoria',
            'hora_inicio.date_format' => 'La hora de inicio debe estar en formato HH:MM',
            'hora_fin.required' => 'La hora de fin es obligatoria',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio',
            'dias_semana.required' => 'Debe seleccionar al menos un día',
            'dias_semana.*.min' => 'Los días deben estar entre 1 y 7',
            'dias_semana.*.max' => 'Los días deben estar entre 1 y 7',
            'dias_semana.*.distinct' => 'No puede haber días duplicados',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'es_activo' => $this->es_activo ?? true,
        ]);
    }
}
