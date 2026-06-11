<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Público - no requiere autenticación
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // Solicitante: estudiante registrado
            'persona_id' => 'nullable|uuid|exists:personas,id',
            
            // Solicitante: datos de participante externo (si no tiene persona_id)
            'correo' => 'nullable|email|max:150',
            'nombres' => 'nullable|string|max:100',
            'apellidos' => 'nullable|string|max:100',
            'cedula' => 'nullable|string|max:20',
            'celular' => 'nullable|string|max:20',
            
            // Curso y pago
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
            'monto_solicitado' => 'required|numeric|min:0.01',
            'tipo_pago' => 'required|in:completo,abono',
            
            // Comprobante (URL o archivo)
            'archivo_comprobante_url' => 'nullable|string|max:500',
            'archivo_comprobante' => 'nullable|file|image|max:5120',
            'archivo_cedula_url' => 'nullable|string|max:500',
            'archivo_cedula' => 'nullable|file|image|max:5120',
            'tipo_comprobante' => 'required|in:transferencia,deposito,efectivo,otro',
            'fecha_pago_declarada' => 'required|date|before_or_equal:today',
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
            'persona_id.uuid' => 'El ID del estudiante debe ser un UUID válido',
            'persona_id.exists' => 'El estudiante especificado no existe',
            'correo.email' => 'El correo debe ser válido',
            'nombres.required_without' => 'Si no es estudiante registrado, debe proporcionar su nombre',
            'apellidos.required_without' => 'Si no es estudiante registrado, debe proporcionar su apellido',
            'curso_abierto_id.required' => 'Debe seleccionar un curso',
            'curso_abierto_id.uuid' => 'El ID del curso debe ser un UUID válido',
            'curso_abierto_id.exists' => 'El curso seleccionado no existe',
            'monto_solicitado.required' => 'Debe especificar el monto a pagar',
            'monto_solicitado.numeric' => 'El monto debe ser un número válido',
            'monto_solicitado.min' => 'El monto debe ser mayor a 0',
            'tipo_pago.required' => 'Debe especificar el tipo de pago',
            'tipo_pago.in' => 'El tipo de pago debe ser "completo" o "abono"',
            'archivo_comprobante_url.required' => 'Debe adjuntar un comprobante de pago',
            'archivo_comprobante_url.url' => 'La URL del comprobante no es válida',
            'tipo_comprobante.required' => 'Debe especificar el tipo de comprobante',
            'tipo_comprobante.in' => 'El tipo de comprobante debe ser uno de: transferencia, deposito, efectivo, otro',
            'fecha_pago_declarada.required' => 'Debe especificar la fecha en que realizó el pago',
            'fecha_pago_declarada.date' => 'La fecha de pago debe ser una fecha válida',
            'fecha_pago_declarada.before_or_equal' => 'La fecha de pago no puede ser en el futuro',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Si es estudiante registrado
        if ($this->has('persona_id') && !empty($this->persona_id)) {
            $this->merge([
                'correo' => null,
                'nombres' => null,
                'apellidos' => null,
                'cedula' => null,
                'celular' => null,
            ]);
        }
    }
}
