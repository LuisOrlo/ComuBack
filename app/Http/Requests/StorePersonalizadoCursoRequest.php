<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalizadoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'docente_id' => ['nullable', 'uuid', 'exists:personas,id'],
            'modalidad' => ['required', 'in:presencial,virtual'],
            'ciudad_id' => ['nullable', 'integer', 'exists:ciudades,id'],
            'fecha_inicio' => ['required', 'date', 'after_or_equal:today'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'precio_total' => ['required', 'numeric', 'min:0'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:500'],
            // Opcional: no se inventan días si el contrato no los envía.
            'dias_semana' => ['nullable', 'array', 'min:1', 'max:7'],
            'dias_semana.*' => ['integer', 'min:1', 'max:7', 'distinct'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('ciudad_id', 'required|integer|exists:ciudades,id', function ($input) {
            return $input->modalidad === 'presencial';
        });
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio',
            'docente_id.exists' => 'El docente no existe',
            'modalidad.required' => 'La modalidad es obligatoria',
            'modalidad.in' => 'La modalidad debe ser presencial o virtual',
            'ciudad_id.required' => 'La ciudad es obligatoria para cursos presenciales',
            'fecha_inicio.after_or_equal' => 'La fecha de inicio no puede ser anterior a hoy',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la hora de inicio',
            'precio_total.required' => 'El precio total es obligatorio',
            'precio_total.min' => 'El precio total no puede ser negativo',
            'capacidad.required' => 'La capacidad máxima es obligatoria',
            'capacidad.min' => 'La capacidad debe ser mínimo 1 estudiante',
        ];
    }
}
