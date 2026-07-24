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
        return [
            'nombre' => 'sometimes|string|max:200',
            'descripcion' => 'nullable|string|max:2000',
            'fecha' => 'sometimes|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha',
            'hora_inicio' => 'sometimes|date_format:H:i',
            'hora_fin' => 'sometimes|date_format:H:i',
            'instructor_id' => 'sometimes|nullable|uuid|exists:personas,id',
            'modalidad' => 'sometimes|in:presencial,virtual',
            'ciudad_id' => 'nullable|integer|exists:ciudades,id',
            'capacidad_maxima' => 'sometimes|integer|min:1|max:500',
            'precio' => 'sometimes|numeric|min:0',
            'estado' => 'sometimes|in:pendiente,confirmado,completado,cancelado',
            'horarios' => 'nullable|array',
            'horarios.*.dia_semana' => 'required_with:horarios|integer|between:1,7',
            'horarios.*.hora_inicio' => 'required_with:horarios|date_format:H:i',
            'horarios.*.hora_fin' => 'required_with:horarios|date_format:H:i|after:horarios.*.hora_inicio',
            'horarios.*.aula' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'instructor_id.exists' => 'El instructor seleccionado no existe',
            'modalidad.in' => 'La modalidad debe ser presencial o virtual',
            'capacidad_maxima.integer' => 'La capacidad debe ser un número entero',
            'capacidad_maxima.min' => 'La capacidad mínima es 1',
            'precio.numeric' => 'El precio debe ser un número válido',
            'precio.min' => 'El precio no puede ser negativo',
        ];
    }
}
