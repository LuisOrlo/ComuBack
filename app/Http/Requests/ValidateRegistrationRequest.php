<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Solo staff puede validar - delegado al controller
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
            'observaciones_validacion' => 'nullable|string|max:500',
            'pagos' => 'nullable|array',
            'pagos.*.modulo_id' => 'required_with:pagos|uuid|exists:pgsql.modulos,id',
            'pagos.*.monto' => 'required_with:pagos|numeric|min:0.01',
            'pagos.*.monto_ajustado' => 'nullable|numeric|min:0',
            'pagos.*.motivo_ajuste' => 'required_with:pagos.*.monto_ajustado|nullable|string|max:255',
            'metodo_pago' => 'nullable|string|in:efectivo,transferencia,deposito,tarjeta,otro',
            'precio_inscripcion' => 'nullable|numeric|min:0.01',
            'inscripcion_cubierta' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'observaciones_validacion.max' => 'Las observaciones no pueden exceder 500 caracteres',
            'pagos.*.modulo_id.required' => 'El ID del módulo es obligatorio para cada pago',
            'pagos.*.modulo_id.exists' => 'El módulo seleccionado no existe',
            'pagos.*.monto.required' => 'El monto es obligatorio para cada pago',
            'pagos.*.monto.min' => 'El monto mínimo es 0.01',
            'metodo_pago.in' => 'El método de pago no es válido',
        ];
    }
}
