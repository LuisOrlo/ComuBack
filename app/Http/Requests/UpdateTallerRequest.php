<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTallerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $taller_id = $this->route('id');

        return [
            'codigo' => ['sometimes', 'string', "unique:talleres,codigo,{$taller_id},id", 'max:50'],
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['sometimes', 'in:taller,seminario,workshop,capacitacion'],
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin' => ['sometimes', 'date', 'after:fecha_inicio'],
            'capacidad' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'profesor_id' => ['nullable', 'uuid', 'exists:users,id'],
            'estado' => ['sometimes', 'in:planificado,activo,completado,cancelado'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.unique' => 'El código ya existe',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
            'profesor_id.exists' => 'El profesor no existe',
        ];
    }
}
