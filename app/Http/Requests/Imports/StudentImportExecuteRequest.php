<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class StudentImportExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('confirmed_rows'))) {
            $decoded = json_decode($this->input('confirmed_rows'), true);
            if (is_array($decoded)) {
                $this->merge(['confirmed_rows' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'preview_id' => ['required', 'uuid'],
            'confirmed_rows' => ['required', 'array'],
            'confirmed_rows.*.row_number' => ['required', 'integer', 'min:2'],
            'confirmed_rows.*.identity_decision' => ['nullable', 'in:CREATE_NEW,USE_EXISTING,SKIP'],
            'confirmed_rows.*.selected_existing_persona_id' => ['nullable', 'uuid'],
            'confirmed_rows.*.confirm_name_inference' => ['nullable', 'boolean'],
            'confirmed_rows.*.corrections' => ['nullable', 'array'],
            'confirmed_rows.*.corrections.nombres' => ['nullable', 'string', 'max:100'],
            'confirmed_rows.*.corrections.apellidos' => ['nullable', 'string', 'max:100'],
            'confirmed_rows.*.corrections.cedula' => ['nullable', 'string', 'max:20'],
            'confirmed_rows.*.corrections.correo' => ['nullable', 'email', 'max:150'],
            'confirmed_rows.*.corrections.celular' => ['nullable', 'string', 'max:20'],
            'confirmed_rows.*.corrections.ciudad' => ['nullable', 'string', 'max:100'],
            'enrollment' => ['nullable', 'array'],
            'enrollment.enabled' => ['nullable', 'boolean'],
            'enrollment.curso_abierto_id' => ['nullable', 'uuid'],
            'enrollment.sin_registro_financiero' => ['nullable', 'boolean'],
            'finance' => ['prohibited'],
        ];
    }
}
