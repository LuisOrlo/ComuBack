<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\CursoAbierto;

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
            'motivo_ajuste' => 'nullable|string|max:255',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $solicitud = \App\Models\SolicitudInscripcion::with('cursoAbierto')->find($this->route('id'));
            $curso = $solicitud?->cursoAbierto;

            if (!$curso?->es_personalizado) {
                return;
            }

            $pago = $this->input('inscripcion_cubierta');
            $precio = (float) ($this->input('precio_inscripcion') ?: ($curso->precio_base ?? 0));

            if ($pago === null || (float) $pago <= 0) {
                $validator->errors()->add('inscripcion_cubierta', 'El pago inicial del curso personalizado debe ser mayor que cero');
            } elseif ((float) $pago > $precio) {
                $validator->errors()->add('inscripcion_cubierta', 'El pago inicial no puede superar el precio total del curso');
            }
            if ($this->filled('precio_inscripcion') && (float) $this->input('precio_inscripcion') > (float) ($curso->precio_base ?? 0)) {
                $validator->errors()->add('precio_inscripcion', 'El precio ajustado no puede superar el precio original del curso');
            }
            if ($this->filled('precio_inscripcion')
                && (float) $this->input('precio_inscripcion') < (float) ($curso->precio_base ?? 0)
                && trim((string) $this->input('motivo_ajuste')) === '') {
                $validator->errors()->add('motivo_ajuste', 'Debes indicar el motivo del descuento');
            }
        });
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
