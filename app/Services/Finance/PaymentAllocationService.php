<?php

namespace App\Services\Finance;

use App\Models\CuentaPorCobrar;
use App\Models\Finance\LineaPagoModulo;
use App\Models\TransaccionIngreso;
use Illuminate\Validation\ValidationException;

class PaymentAllocationService
{
    public const VALID_METHODS = ['efectivo', 'transferencia', 'deposito', 'tarjeta', 'otro'];
    public const MAX_METHODS = 5;

    /**
     * Valida y normaliza el arreglo de métodos de pago.
     * Retorna un arreglo con montos en centavos y datos listos.
     */
    public function validateAndNormalizeMethods(array $metodos, int $totalEsperadoCentavos): array
    {
        if (empty($metodos)) {
            throw ValidationException::withMessages([
                'metodos' => 'Debe ingresar al menos un método de pago.',
            ]);
        }

        if (count($metodos) > self::MAX_METHODS) {
            throw ValidationException::withMessages([
                'metodos' => 'No se permite ingresar más de ' . self::MAX_METHODS . ' métodos de pago en una sola operación.',
            ]);
        }

        $sumaMetodosCentavos = 0;
        $normalized = [];

        foreach ($metodos as $index => $m) {
            $metodo = trim((string) ($m['metodo_pago'] ?? ''));
            if (!in_array($metodo, self::VALID_METHODS, true)) {
                throw ValidationException::withMessages([
                    "metodos.{$index}.metodo_pago" => "El método de pago '{$metodo}' no es válido.",
                ]);
            }

            $monto = (float) ($m['monto'] ?? 0);
            $montoCentavos = (int) round($monto * 100);

            if ($montoCentavos <= 0) {
                throw ValidationException::withMessages([
                    "metodos.{$index}.monto" => 'Cada método de pago debe tener un monto mayor a cero.',
                ]);
            }

            $referencia = trim((string) ($m['referencia_pago'] ?? ''));
            if (in_array($metodo, ['transferencia', 'deposito'], true) && empty($referencia)) {
                // Si no viene referencia por método, verificar si enviaron una referencia global
                $referencia = trim((string) ($m['referencia'] ?? $m['numero_comprobante'] ?? ''));
            }

            $sumaMetodosCentavos += $montoCentavos;

            $normalized[] = [
                'metodo_pago' => $metodo,
                'monto' => round($montoCentavos / 100, 2),
                'monto_centavos' => $montoCentavos,
                'remaining_centavos' => $montoCentavos,
                'linea_pago_modulo_id' => !empty($m['linea_pago_modulo_id']) ? (string) $m['linea_pago_modulo_id'] : null,
                'referencia_pago' => $referencia !== '' ? $referencia : null,
                'comprobante_url' => !empty($m['comprobante_url']) ? trim((string) $m['comprobante_url']) : null,
                'fecha_pago' => !empty($m['fecha_pago']) ? $m['fecha_pago'] : null,
            ];
        }

        if ($sumaMetodosCentavos !== $totalEsperadoCentavos) {
            $diffDolares = number_format(($sumaMetodosCentavos - $totalEsperadoCentavos) / 100, 2);
            $esperadoDolares = number_format($totalEsperadoCentavos / 100, 2);
            $ingresadoDolares = number_format($sumaMetodosCentavos / 100, 2);
            throw ValidationException::withMessages([
                'metodos' => "La suma de los métodos de pago (\${$ingresadoDolares}) no coincide con el total a pagar (\${$esperadoDolares}). Diferencia: \${$diffDolares}.",
            ]);
        }

        return $normalized;
    }

    /**
     * Distribuye determinísticamente en cascada los métodos de pago entre las líneas de pago (módulos / matrícula).
     *
     * @param array $lineasItems Array de [ 'linea' => LineaPagoModulo, 'monto' => float, 'monto_ajustado' => ?float, 'motivo_ajuste' => ?string ]
     * @param array $metodosNormalizados Array retornado por validateAndNormalizeMethods
     * @param string $grupoPagoId UUID del grupo de pago (idempotencia)
     * @param string|null $registradoPor ID de usuario que registra
     * @param string|null $comprobanteUrlGlobal URL de comprobante de respaldo global
     * @return array Transacciones creadas
     */
    public function allocateModulePayments(
        array $lineasItems,
        array $metodosNormalizados,
        string $grupoPagoId,
        ?string $registradoPor = null,
        ?string $comprobanteUrlGlobal = null
    ): array {
        $transacciones = [];

        $hasExplicitLineas = collect($metodosNormalizados)->contains(fn($m) => !empty($m['linea_pago_modulo_id']));

        if ($hasExplicitLineas) {
            foreach ($lineasItems as $item) {
                /** @var LineaPagoModulo $linea */
                $linea = $item['linea'];
                $montoCentavos = (int) round(((float) $item['monto']) * 100);

                if (isset($item['monto_ajustado']) && $item['monto_ajustado'] !== null && $item['monto_ajustado'] !== '') {
                    $nuevoAjustado = (float) $item['monto_ajustado'];
                    if ($nuevoAjustado != (float) $linea->monto_ajustado) {
                        $linea->update([
                            'monto_ajustado' => $nuevoAjustado,
                            'motivo_ajuste' => $item['motivo_ajuste'] ?? null,
                            'ajustado_por' => $registradoPor,
                            'fecha_ajuste' => now(),
                        ]);
                        $linea->refresh();
                    }
                }

                if ($montoCentavos <= 0) {
                    $linea->recalcularEstado();
                    $linea->save();
                    continue;
                }

                $saldoDisponible = max((float) $linea->monto_ajustado - (float) $linea->monto_abonado, 0.0);
                $saldoCentavos = (int) round($saldoDisponible * 100);

                if ($montoCentavos > $saldoCentavos) {
                    $maxPermitido = number_format($saldoDisponible, 2);
                    throw ValidationException::withMessages([
                        'lineas' => "El monto a pagar en '{$linea->nombre_modulo}' excede su saldo pendiente (\${$maxPermitido}).",
                    ]);
                }

                $metodosDeLinea = array_filter(
                    $metodosNormalizados,
                    fn($m) => ($m['linea_pago_modulo_id'] ?? null) === $linea->id
                );

                foreach ($metodosDeLinea as $m) {
                    $tx = TransaccionIngreso::create([
                        'linea_pago_modulo_id' => $linea->id,
                        'grupo_pago_id' => $grupoPagoId,
                        'monto' => $m['monto'],
                        'metodo_pago' => $m['metodo_pago'],
                        'referencia_pago' => $m['referencia_pago'] ?? null,
                        'comprobante_url' => $m['comprobante_url'] ?: $comprobanteUrlGlobal,
                        'fecha_pago' => $m['fecha_pago'] ?? now(),
                        'registrado_por' => $registradoPor,
                        'verificado_por' => $registradoPor,
                        'fecha_verificacion' => now(),
                        'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                    ]);
                    $transacciones[] = $tx;
                }
                $linea->refresh();
            }
            return $transacciones;
        }

        $methodIdx = 0;
        $totalMethods = count($metodosNormalizados);

        foreach ($lineasItems as $item) {
            /** @var LineaPagoModulo $linea */
            $linea = $item['linea'];
            $montoCentavos = (int) round(((float) $item['monto']) * 100);

            // Aplicar ajuste de precio si viene especificado
            if (isset($item['monto_ajustado']) && $item['monto_ajustado'] !== null && $item['monto_ajustado'] !== '') {
                $nuevoAjustado = (float) $item['monto_ajustado'];
                if ($nuevoAjustado != (float) $linea->monto_ajustado) {
                    $linea->update([
                        'monto_ajustado' => $nuevoAjustado,
                        'motivo_ajuste' => $item['motivo_ajuste'] ?? null,
                        'ajustado_por' => $registradoPor,
                        'fecha_ajuste' => now(),
                    ]);
                    $linea->refresh();
                }
            }

            if ($montoCentavos <= 0) {
                $linea->recalcularEstado();
                $linea->save();
                continue;
            }

            // Validar contra saldo pendiente de la línea
            $saldoDisponible = max((float) $linea->monto_ajustado - (float) $linea->monto_abonado, 0.0);
            $saldoCentavos = (int) round($saldoDisponible * 100);

            if ($montoCentavos > $saldoCentavos) {
                $maxPermitido = number_format($saldoDisponible, 2);
                throw ValidationException::withMessages([
                    'lineas' => "El monto a pagar en '{$linea->nombre_modulo}' excede su saldo pendiente (\${$maxPermitido}).",
                ]);
            }

            $neededCentavos = $montoCentavos;

            while ($neededCentavos > 0 && $methodIdx < $totalMethods) {
                $avail = $metodosNormalizados[$methodIdx]['remaining_centavos'];
                if ($avail <= 0) {
                    $methodIdx++;
                    continue;
                }

                $takeCentavos = min($neededCentavos, $avail);
                $takeMonto = round($takeCentavos / 100, 2);
                $curMethod = $metodosNormalizados[$methodIdx];

                $tx = TransaccionIngreso::create([
                    'linea_pago_modulo_id' => $linea->id,
                    'grupo_pago_id' => $grupoPagoId,
                    'monto' => $takeMonto,
                    'metodo_pago' => $curMethod['metodo_pago'],
                    'referencia_pago' => $curMethod['referencia_pago'],
                    'comprobante_url' => $curMethod['comprobante_url'] ?: $comprobanteUrlGlobal,
                    'fecha_pago' => $curMethod['fecha_pago'] ?? now(),
                    'registrado_por' => $registradoPor,
                    'verificado_por' => $registradoPor,
                    'fecha_verificacion' => now(),
                    'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                ]);

                $transacciones[] = $tx;

                $neededCentavos -= $takeCentavos;
                $metodosNormalizados[$methodIdx]['remaining_centavos'] -= $takeCentavos;

                if ($metodosNormalizados[$methodIdx]['remaining_centavos'] <= 0) {
                    $methodIdx++;
                }
            }

            $linea->refresh();
        }

        return $transacciones;
    }

    /**
     * Aplica pagos múltiples a una CuentaPorCobrar directa (Talleres, Servicios, etc.).
     */
    public function allocateDirectPayment(
        CuentaPorCobrar $cuenta,
        array $metodosNormalizados,
        string $grupoPagoId,
        ?string $registradoPor = null,
        ?string $observaciones = null,
        ?string $comprobanteUrlGlobal = null
    ): array {
        $saldo = (float) $cuenta->monto_total - (float) $cuenta->monto_abonado;
        $saldoCentavos = (int) round($saldo * 100);
        $totalPagarCentavos = array_sum(array_column($metodosNormalizados, 'monto_centavos'));

        if ($totalPagarCentavos > $saldoCentavos) {
            $saldoStr = number_format($saldo, 2);
            $totalStr = number_format($totalPagarCentavos / 100, 2);
            throw ValidationException::withMessages([
                'monto' => "El monto total a registrar (\${$totalStr}) supera el saldo pendiente (\${$saldoStr}).",
            ]);
        }

        $transacciones = [];
        foreach ($metodosNormalizados as $m) {
            $tx = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $cuenta->id,
                'grupo_pago_id' => $grupoPagoId,
                'monto' => $m['monto'],
                'metodo_pago' => $m['metodo_pago'],
                'referencia_pago' => $m['referencia_pago'],
                'comprobante_url' => $m['comprobante_url'] ?: $comprobanteUrlGlobal,
                'fecha_pago' => $m['fecha_pago'] ?? now(),
                'registrado_por' => $registradoPor,
                'observaciones' => $observaciones,
                'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                'verificado_por' => $registradoPor,
                'fecha_verificacion' => now(),
            ]);
            $transacciones[] = $tx;
        }

        return $transacciones;
    }
}
