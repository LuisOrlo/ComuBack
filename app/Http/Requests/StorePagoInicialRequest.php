<?php

namespace App\Http\Requests;

use App\Models\Finance\LineaPagoModulo;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePagoInicialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matricula_id' => 'required|uuid|exists:academic.matriculas,id',
            'pagos' => 'required|array|min:1',
            'pagos.*.linea_pago_modulo_id' => [
                'required',
                'uuid',
                Rule::exists('finance.lineas_pago_modulo', 'id')
                    ->where('matricula_id', $this->input('matricula_id')),
            ],
            'pagos.*.monto' => [
                'required',
                'numeric',
                'min:0.01',
                function (string $attribute, mixed $value, Closure $fail) {
                    $index = explode('.', $attribute)[1];
                    $lineaId = $this->input("pagos.{$index}.linea_pago_modulo_id");
                    if (! $lineaId) {
                        return;
                    }

                    $linea = LineaPagoModulo::find($lineaId);
                    if (! $linea) {
                        return;
                    }

                    // Si viene un ajuste de precio, validamos contra el nuevo monto_ajustado
                    $montoAjustado = $this->input("pagos.{$index}.monto_ajustado");
                    $montoTope = $montoAjustado !== null ? (float) $montoAjustado : $linea->monto_ajustado;
                    $saldo = max($montoTope - $linea->monto_abonado, 0);
                    if ((float) $value > $saldo + 0.001) {
                        $fail("El monto ingresado (\${$value}) excede el saldo pendiente del módulo. Máximo permitido: \${$saldo}.");
                    }
                },
            ],
            'pagos.*.monto_ajustado' => 'nullable|numeric|min:0',
            'pagos.*.motivo_ajuste' => 'required_with:pagos.*.monto_ajustado|nullable|string|max:255',
            'pagos.*.metodo_pago' => 'required|string|in:efectivo,transferencia,deposito,tarjeta,otro',
            'pagos.*.fecha_pago' => 'nullable|date',
            'pagos.*.comprobante_url' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_id.required' => 'El ID de la matrícula es obligatorio',
            'matricula_id.exists' => 'La matrícula no existe',
            'pagos.required' => 'Debe enviar al menos un pago',
            'pagos.*.linea_pago_modulo_id.required' => 'El ID de la línea de pago es obligatorio',
            'pagos.*.linea_pago_modulo_id.exists' => 'La línea de pago no pertenece a la matrícula indicada',
            'pagos.*.monto.required' => 'El monto es obligatorio',
            'pagos.*.monto.min' => 'El monto mínimo es 0.01',
            'pagos.*.metodo_pago.required' => 'El método de pago es obligatorio',
            'pagos.*.metodo_pago.in' => 'Método de pago no válido',
        ];
    }
}
