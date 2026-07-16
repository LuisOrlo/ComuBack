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
            'nombres' => 'required_without:persona_id|string|max:100|min:2',
            'apellidos' => 'required_without:persona_id|string|max:100|min:2',
            'correo' => 'required_without:persona_id|email|max:150',
            'tipo_id' => 'required_without:persona_id|in:cedula,dni',
            'cedula' => [
                'required_without:persona_id',
                'string',
                'max:20',
                function ($attribute, $value, $fail) {
                    if ($this->tipo_id === 'cedula' && !empty($value)) {
                        if (!preg_match('/^\d{10}$/', $value)) {
                            $fail('La cédula debe tener exactamente 10 dígitos numéricos.');
                        }
                    } elseif ($this->tipo_id === 'dni' && !empty($value)) {
                        if (strlen($value) < 5) {
                            $fail('El DNI debe tener al menos 5 caracteres.');
                        }
                    }
                },
            ],
            'celular' => 'required_without:persona_id|string|max:20',
            'ocupacion' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:1000',
            'ciudad' => 'nullable|string|max:100',
            'estado_civil' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'edad' => 'nullable|integer|min:0|max:150',
            
            // Curso y pago
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
            'monto_solicitado' => 'required|numeric|min:0',
            'tipo_pago' => 'required|in:completo,abono',
            
            // Comprobante (URL o archivo)
            'archivo_comprobante_url' => 'nullable|string|max:500',
            'archivo_comprobante' => 'nullable|file|image|max:5120',
            'archivo_cedula_url' => 'required_without:archivo_cedula|nullable|string|max:500',
            'archivo_cedula' => 'required_without:archivo_cedula_url|nullable|file|image|max:2048',
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
            'persona_id.uuid' => 'El ID del estudiante no es válido',
            'persona_id.exists' => 'El estudiante especificado no existe',
            'correo.email' => 'El correo debe ser válido',
            'correo.required_without' => 'Si no es estudiante registrado, debe proporcionar su correo',
            'correo.max' => 'El correo no debe tener más de 150 caracteres',
            'nombres.required_without' => 'Si no es estudiante registrado, debe proporcionar su nombre',
            'nombres.min' => 'El nombre debe tener al menos 2 caracteres',
            'nombres.max' => 'El nombre no debe tener más de 100 caracteres',
            'apellidos.required_without' => 'Si no es estudiante registrado, debe proporcionar su apellido',
            'apellidos.min' => 'Los apellidos deben tener al menos 2 caracteres',
            'apellidos.max' => 'Los apellidos no deben tener más de 100 caracteres',
            'tipo_id.in' => 'El tipo de identificación debe ser "cedula" o "dni"',
            'cedula.required_without' => 'Si no es estudiante registrado, debe proporcionar su cédula o DNI',
            'cedula.max' => 'La cédula o DNI no debe tener más de 20 caracteres',
            'celular.required_without' => 'Si no es estudiante registrado, debe proporcionar su teléfono',
            'celular.max' => 'El teléfono no debe tener más de 20 caracteres',
            'ocupacion.max' => 'La ocupación no debe tener más de 100 caracteres',
            'direccion.max' => 'La dirección no debe tener más de 1000 caracteres',
            'ciudad.max' => 'La ciudad no debe tener más de 100 caracteres',
            'estado_civil.max' => 'El estado civil no debe tener más de 20 caracteres',
            'fecha_nacimiento.date' => 'La fecha de nacimiento no es válida',
            'edad.integer' => 'La edad debe ser un número entero',
            'edad.min' => 'La edad debe ser un valor positivo',
            'edad.max' => 'La edad ingresada no es válida',
            'curso_abierto_id.required' => 'Debe seleccionar un curso',
            'curso_abierto_id.uuid' => 'El ID del curso no es válido',
            'curso_abierto_id.exists' => 'El curso seleccionado no existe',
            'monto_solicitado.required' => 'Debe especificar el monto a pagar',
            'monto_solicitado.numeric' => 'El monto debe ser un número válido',
            'monto_solicitado.min' => 'El monto no puede ser negativo',
            'tipo_pago.required' => 'Debe especificar el tipo de pago',
            'tipo_pago.in' => 'El tipo de pago debe ser "completo" o "abono"',
            'archivo_comprobante_url.max' => 'La URL del comprobante es demasiado larga',
            'archivo_comprobante_url.url' => 'La URL del comprobante no es válida',
            'archivo_comprobante.max' => 'El comprobante no debe superar los 5 MB',
            'archivo_comprobante.image' => 'El comprobante debe ser una imagen',
            'archivo_comprobante.file' => 'El comprobante debe ser un archivo válido',
            'archivo_cedula_url.max' => 'La URL de la cédula es demasiado larga',
            'archivo_cedula.max' => 'La foto de cédula no debe superar los 2 MB',
            'archivo_cedula.image' => 'La foto de cédula debe ser una imagen',
            'archivo_cedula.file' => 'La foto de cédula debe ser un archivo válido',
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
        // Si es estudiante registrado, limpiar campos de datos personales
        // (solo los que identificarían/crearían un ClienteExterno duplicado)
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
