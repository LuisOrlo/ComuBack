<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Solo staff puede rechazar
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'motivo_rechazo' => 'required|string|min:10|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo_rechazo.required' => 'Debe proporcionar un motivo para rechazar la solicitud',
            'motivo_rechazo.min' => 'El motivo debe tener al menos 10 caracteres',
            'motivo_rechazo.max' => 'El motivo no puede exceder 500 caracteres',
        ];
    }
}
