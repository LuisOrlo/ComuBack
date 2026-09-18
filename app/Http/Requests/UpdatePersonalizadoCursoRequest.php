<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\CursoAbierto;

class UpdatePersonalizadoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'docente_id' => ['sometimes', 'nullable', 'uuid', 'exists:personas,id'],
            'modalidad' => ['sometimes', 'required', 'in:presencial,virtual'],
            'ciudad_id' => ['sometimes', 'nullable', 'integer', 'exists:ciudades,id'],
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin' => ['sometimes', 'date', 'after_or_equal:fecha_inicio'],
            'hora_inicio' => ['sometimes', 'date_format:H:i'],
            'hora_fin' => ['sometimes', 'date_format:H:i', 'after:hora_inicio'],
            'precio_total' => ['sometimes', 'numeric', 'min:0'],
            'capacidad' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'dias_semana' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            'dias_semana.*' => ['integer', 'min:1', 'max:7', 'distinct'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('ciudad_id', 'required|integer|exists:ciudades,id', function ($input) {
            return $input->modalidad === 'presencial';
        });

        $validator->after(function ($validator) {
            $curso = CursoAbierto::find($this->route('id'));

            if (!$curso) {
                return;
            }

            $fechaInicio = $this->input('fecha_inicio', $curso->fecha_inicio);
            $fechaFin = $this->input('fecha_fin', $curso->fecha_fin);

            if ($fechaInicio && $fechaFin && strtotime($fechaFin) < strtotime($fechaInicio)) {
                $validator->errors()->add('fecha_fin', 'La fecha de fin no puede ser anterior a la fecha de inicio');
            }

            $horaInicio = $this->input('hora_inicio', $curso->horario?->hora_inicio);
            $horaFin = $this->input('hora_fin', $curso->horario?->hora_fin);

            if ($horaInicio && $horaFin && strtotime($horaFin) <= strtotime($horaInicio)) {
                $validator->errors()->add('hora_fin', 'La hora de fin debe ser posterior a la hora de inicio');
            }

            if ($this->filled('capacidad')) {
                $ocupados = $curso->obtenerCountMatriculas();
                if ((int) $this->input('capacidad') < $ocupados) {
                    $validator->errors()->add(
                        'capacidad',
                        "La capacidad no puede ser menor que las {$ocupados} matrículas que ocupan cupo"
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'docente_id.exists' => 'El docente no existe',
            'modalidad.in' => 'La modalidad debe ser presencial o virtual',
            'ciudad_id.required' => 'La ciudad es obligatoria para cursos presenciales',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la hora de inicio',
            'precio_total.min' => 'El precio total no puede ser negativo',
            'capacidad.min' => 'La capacidad debe ser mínimo 1 estudiante',
        ];
    }
}
