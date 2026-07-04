<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTareaStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => 'sometimes|string|max:200',
            'descripcion' => 'nullable|string',
            'persona_id' => 'sometimes|uuid|exists:people.personas,id',
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'estado' => 'sometimes|in:pendiente,en_progreso,completada,cancelada',
        ];
    }
}
