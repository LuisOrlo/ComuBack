<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalizadoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $curso_id = $this->route('id');

        return [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin' => ['sometimes', 'date', 'after:fecha_inicio'],
            'capacidad' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'estado' => ['sometimes', 'in:abierto,en_curso,completado,cancelado'],
            'dirigido_a' => ['nullable', 'string', 'max:500'],
            'requisitos_especiales' => ['nullable', 'string', 'max:500'],
            'certificado_emitido' => ['sometimes', 'boolean'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'acepta_externos' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
            'capacidad.min' => 'La capacidad debe ser mínimo 1',
        ];
    }
}
