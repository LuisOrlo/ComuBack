<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'required|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'El campo ids es requerido',
            'ids.array' => 'Los IDs deben ser un array',
            'ids.min' => 'Se requiere al menos 1 ID',
            'ids.max' => 'Máximo 1000 IDs por solicitud',
            'ids.*.required' => 'ID requerido',
            'ids.*.uuid' => 'ID inválido',
        ];
    }
}
