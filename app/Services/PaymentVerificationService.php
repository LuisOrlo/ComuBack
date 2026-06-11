<?php

namespace App\Services;

use Carbon\Carbon;

class PaymentVerificationService
{
    /**
     * Tipos de comprobante válidos
     */
    private const TIPOS_COMPROBANTE_VALIDOS = [
        'transferencia',
        'deposito',
        'efectivo',
        'otro',
    ];

    /**
     * Validar comprobante de pago
     * 
     * @param string|null $archivoUrl URL del archivo comprobante
     * @param string|null $tipoComprobante Tipo de comprobante
     * @param string|null $fechaPagoDec larada Fecha declarada del pago
     * @param float $montoSolicitado Monto a validar
     * @param float $precioBase Precio base del curso
     * 
     * @return array ['valido' => bool, 'errores' => [], 'recomendaciones' => []]
     */
    public function validar(
        ?string $archivoUrl,
        ?string $tipoComprobante,
        ?string $fechaPagoDeclarada,
        float $montoSolicitado,
        float $precioBase
    ): array {
        $errores = [];
        $recomendaciones = [];

        // 1. Validar que existe archivo comprobante
        if (empty($archivoUrl)) {
            $errores[] = 'Debe proporcionar un archivo de comprobante';
        } else if (!$this->esUrlValida($archivoUrl)) {
            $errores[] = 'La URL del comprobante no es válida';
        }

        // 2. Validar tipo de comprobante
        if (empty($tipoComprobante)) {
            $errores[] = 'Debe especificar el tipo de comprobante';
        } elseif (!in_array($tipoComprobante, self::TIPOS_COMPROBANTE_VALIDOS)) {
            $errores[] = 'Tipo de comprobante no válido. Debe ser uno de: ' . implode(', ', self::TIPOS_COMPROBANTE_VALIDOS);
        }

        // 3. Validar fecha de pago
        if (empty($fechaPagoDeclarada)) {
            $errores[] = 'Debe proporcionar la fecha en que realizó el pago';
        } else {
            try {
                $fechaPago = Carbon::parse($fechaPagoDeclarada);

                if ($fechaPago->isFuture()) {
                    $errores[] = 'La fecha de pago no puede ser en el futuro';
                }

                // Recomendación: si el pago fue hace más de 30 días
                if ($fechaPago->diffInDays(Carbon::now()) > 30) {
                    $recomendaciones[] = 'El pago fue hace más de 30 días. Asegúrese de que el comprobante es válido.';
                }
            } catch (\Exception $e) {
                $errores[] = 'La fecha de pago no es válida';
            }
        }

        // 4. Validar coherencia del monto
        if ($montoSolicitado > 0 && $precioBase > 0) {
            if ($montoSolicitado > $precioBase * 1.05) { // Permitir 5% de diferencia
                $recomendaciones[] = "El monto solicitado ({$montoSolicitado}) es mayor al precio del curso ({$precioBase}). Verifique.";
            }

            if ($montoSolicitado < $precioBase * 0.5 && $montoSolicitado != 0) {
                $recomendaciones[] = "El monto solicitado ({$montoSolicitado}) es menos del 50% del precio del curso ({$precioBase}). Podría tratarse de un abono incompleto.";
            }
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
            'recomendaciones' => $recomendaciones,
        ];
    }

    /**
     * Validar URL
     * 
     * @param string $url
     * @return bool
     */
    private function esUrlValida(string $url): bool
    {
        // Permitir URLs http/https o rutas locales
        return (filter_var($url, FILTER_VALIDATE_URL) !== false) ||
               (strpos($url, '/') === 0 || strpos($url, 'storage/') === 0);
    }

    /**
     * Validar tipos de comprobante
     * 
     * @return array
     */
    public static function getTiposComprobanteValidos(): array
    {
        return self::TIPOS_COMPROBANTE_VALIDOS;
    }
}
