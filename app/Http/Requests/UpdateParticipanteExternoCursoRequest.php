<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateParticipanteExternoCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['sometimes', 'in:inscrito,completado,retirado'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado no es válido',
        ];
    }
}
