<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePerfilStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cargo' => 'required|string|max:100',
            'salario_base' => 'nullable|numeric|min:0',
            'fecha_ingreso' => 'nullable|date',
            'es_pasante' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'cargo.required' => 'El cargo es obligatorio',
        ];
    }
}
