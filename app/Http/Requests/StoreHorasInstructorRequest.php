<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHorasInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instructor_id' => 'required|uuid|exists:personas,id',
            'curso_abierto_id' => 'nullable|uuid|exists:cursos_abiertos,id',
            'fecha' => 'required|date',
            'horas_trabajadas' => 'required|numeric|min:0.5|max:24',
            'tarifa_aplicada' => 'required|numeric|min:0',
        ];
    }
}
