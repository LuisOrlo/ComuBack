<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'unique:talleres,codigo', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['required', 'in:taller,seminario,workshop,capacitacion'],
            'fecha_inicio' => ['required', 'date', 'after_or_equal:today'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:500'],
            'profesor_id' => ['nullable', 'uuid', 'exists:users,id'],
            'estado' => ['required', 'in:planificado,activo,completado,cancelado'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El código es obligatorio',
            'codigo.unique' => 'El código ya existe',
            'nombre.required' => 'El nombre es obligatorio',
            'fecha_inicio.after_or_equal' => 'La fecha de inicio no puede ser en el pasado',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
            'profesor_id.exists' => 'El profesor no existe',
        ];
    }
}
