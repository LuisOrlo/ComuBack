<?php

namespace App\Services\Finance;

use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;

class PagoInicialServicioService
{
    public const METODOS_PERMITIDOS = ['efectivo', 'transferencia', 'deposito', 'tarjeta', 'otro'];

    /**
     * Procesa el pago inicial (único o mixto) para una o varias CuentasPorCobrar de servicios.
     *
     * @param CuentaPorCobrar[] $cuentas Array de modelos CuentaPorCobrar creadas en la misma transacción
     * @param array $pagoData Datos del pago recibidos en la request ('pago' o 'metodos_pago')
     * @param string|null $registradoPor UUID de la persona/usuario que registra el cobro
     * @return array Resumen del procesamiento ['grupo_pago_id', 'total_pagado', 'transacciones_creadas']
     */
    public function procesarPagoInicial(array $cuentas, array $pagoData, ?string $registradoPor = null): array
    {
        // 1. Extraer métodos de pago del payload
        $metodosRaw = [];
        if (!empty($pagoData['metodos_pago']) && is_array($pagoData['metodos_pago'])) {
            $metodosRaw = $pagoData['metodos_pago'];
        } elseif (!empty($pagoData['metodo_pago']) && isset($pagoData['monto'])) {
            $metodosRaw = [
                [
                    'metodo_pago' => $pagoData['metodo_pago'],
                    'monto' => $pagoData['monto'],
                    'referencia_pago' => $pagoData['referencia_pago'] ?? null,
                    'comprobante_url' => $pagoData['comprobante_url'] ?? null,
                    'comprobante_urls' => $pagoData['comprobante_urls'] ?? null,
                    'fecha_pago' => $pagoData['fecha_pago'] ?? null,
                ]
            ];
        }

        if (empty($metodosRaw)) {
            return [
                'total_pagado' => 0,
                'transacciones_creadas' => [],
            ];
        }

        // 2. Calcular total requerido por las cuentas en centavos
        $totalCuentasCentavos = 0;
        foreach ($cuentas as $cuenta) {
            $montoTotal = (float) $cuenta->monto_total;
            $montoAbonado = (float) ($cuenta->monto_abonado ?? 0);
            $saldo = max(0, $montoTotal - $montoAbonado);
            $totalCuentasCentavos += (int) round($saldo * 100);
        }

        // 3. Validar y normalizar cada método de pago
        $sumaMetodosCentavos = 0;
        $metodosNormalizados = [];

        foreach ($metodosRaw as $idx => $m) {
            $metodo = trim((string) ($m['metodo_pago'] ?? ''));
            if (!in_array($metodo, self::METODOS_PERMITIDOS, true)) {
                throw ValidationException::withMessages([
                    "pago.metodos_pago.{$idx}.metodo_pago" => "El método de pago '{$metodo}' no es válido.",
                ]);
            }

            $monto = (float) ($m['monto'] ?? 0);
            $montoCentavos = (int) round($monto * 100);

            if ($montoCentavos <= 0) {
                throw ValidationException::withMessages([
                    "pago.metodos_pago.{$idx}.monto" => 'El monto debe ser mayor a cero ($0.01).',
                ]);
            }

            $sumaMetodosCentavos += $montoCentavos;

            // Manejo de comprobantes (string único o array de URLs de imágenes)
            $comprobanteUrl = null;
            if (!empty($m['comprobante_urls']) && is_array($m['comprobante_urls'])) {
                $comprobanteUrl = trim((string) ($m['comprobante_urls'][0] ?? ''));
            } elseif (!empty($m['comprobante_url'])) {
                $comprobanteUrl = trim((string) $m['comprobante_url']);
            }

            $referencia = trim((string) ($m['referencia_pago'] ?? ''));

            $metodosNormalizados[] = [
                'metodo_pago' => $metodo,
                'monto_centavos' => $montoCentavos,
                'remaining_centavos' => $montoCentavos,
                'referencia_pago' => $referencia !== '' ? $referencia : null,
                'comprobante_url' => !empty($comprobanteUrl) ? $comprobanteUrl : null,
                'fecha_pago' => !empty($m['fecha_pago']) ? $m['fecha_pago'] : now(),
            ];
        }

        // 4. Validar que la suma total de pagos no supere el total de las cuentas
        if ($sumaMetodosCentavos > $totalCuentasCentavos) {
            $sumaDolares = number_format($sumaMetodosCentavos / 100, 2);
            $totalDolares = number_format($totalCuentasCentavos / 100, 2);
            throw ValidationException::withMessages([
                'pago' => "El monto total a pagar (\${$sumaDolares}) supera el precio total de la reserva (\${$totalDolares}).",
            ]);
        }

        // 5. Asignar pagos en cascada cronológica a las cuentas
        $grupoPagoId = (string) Str::uuid();
        $transaccionesCreadas = [];
        $observacionesGlobal = trim((string) ($pagoData['observaciones'] ?? ''));

        foreach ($cuentas as $cuenta) {
            $saldoCuentaCentavos = (int) round(((float) $cuenta->monto_total - (float) ($cuenta->monto_abonado ?? 0)) * 100);
            if ($saldoCuentaCentavos <= 0) {
                continue;
            }

            $abonoEstaCuentaCentavos = 0;

            foreach ($metodosNormalizados as &$metodoRef) {
                if ($metodoRef['remaining_centavos'] <= 0) {
                    continue;
                }

                $necesarioCentavos = $saldoCuentaCentavos - $abonoEstaCuentaCentavos;
                if ($necesarioCentavos <= 0) {
                    break;
                }

                $aplicarCentavos = min($metodoRef['remaining_centavos'], $necesarioCentavos);
                $metodoRef['remaining_centavos'] -= $aplicarCentavos;
                $abonoEstaCuentaCentavos += $aplicarCentavos;

                // Crear TransaccionIngreso
                $tx = TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'grupo_pago_id' => $grupoPagoId,
                    'monto' => round($aplicarCentavos / 100, 2),
                    'metodo_pago' => $metodoRef['metodo_pago'],
                    'referencia_pago' => $metodoRef['referencia_pago'],
                    'comprobante_url' => $metodoRef['comprobante_url'],
                    'fecha_pago' => $metodoRef['fecha_pago'],
                    'registrado_por' => $registradoPor,
                    'observaciones' => $observacionesGlobal !== '' ? $observacionesGlobal : 'Pago inicial registrado con la reserva',
                    'estado_verificacion' => 'aprobado',
                    'verificado_por' => $registradoPor,
                    'fecha_verificacion' => now(),
                ]);

                $transaccionesCreadas[] = $tx;
            }
            unset($metodoRef);

            // Actualizar cuenta
            if ($abonoEstaCuentaCentavos > 0) {
                $cuenta->monto_abonado = round(((float) $cuenta->monto_abonado) + ($abonoEstaCuentaCentavos / 100), 2);
                $nuevoSaldo = (float) $cuenta->monto_total - (float) $cuenta->monto_abonado;
                $cuenta->estado = $nuevoSaldo <= 0.001 ? 'pagado' : 'abonado';
                $cuenta->save();

                // Actualizar estado operativo del servicio asociado
                $this->confirmarServicioAsociado($cuenta);
            }
        }

        Cache::forget('finance.resumen');

        return [
            'grupo_pago_id' => $grupoPagoId,
            'total_pagado' => round($sumaMetodosCentavos / 100, 2),
            'transacciones_creadas' => $transaccionesCreadas,
        ];
    }

    /**
     * Promueve el estado operativo del servicio a 'confirmado' cuando recibe un pago o abono inicial.
     */
    protected function confirmarServicioAsociado(CuentaPorCobrar $cuenta): void
    {
        if ($cuenta->reserva_aula_id && $cuenta->reservaAula && $cuenta->reservaAula->estado === 'reservado') {
            $cuenta->reservaAula->update(['estado' => 'confirmado']);
        }

        if ($cuenta->reserva_podcast_id && $cuenta->reservaPodcast && in_array($cuenta->reservaPodcast->estado, ['pendiente', 'reservado'], true)) {
            $cuenta->reservaPodcast->update(['estado' => 'confirmado']);
        }

        if ($cuenta->reserva_radio_id && $cuenta->reservaRadio && in_array($cuenta->reservaRadio->estado, ['pendiente', 'reservado'], true)) {
            $cuenta->reservaRadio->update(['estado' => 'confirmado']);
        }

        if ($cuenta->reserva_set_id && $cuenta->reservaSet && in_array($cuenta->reservaSet->estado, ['pendiente', 'reservado'], true)) {
            $cuenta->reservaSet->update(['estado' => 'confirmado']);
        }
    }
}
