<?php

namespace App\DTOs\Imports;

final readonly class HistoricalPaymentOptionsDTO
{
    public function __construct(
        public string $fechaPago,
        public string $metodoPago,
        public ?string $comprobanteUrl = null,
        public ?string $observaciones = null,
        public bool $confirmImpact = false,
        public bool $confirmPriceDifferences = false,
    ) {}

    public static function fromArray(array $options): self
    {
        return new self(
            fechaPago: (string) ($options['fecha_pago_default'] ?? ''),
            metodoPago: (string) ($options['metodo_pago'] ?? ''),
            comprobanteUrl: $options['comprobante_url'] ?? null,
            observaciones: $options['observaciones'] ?? null,
            confirmImpact: filter_var($options['confirm_real_financial_impact'] ?? false, FILTER_VALIDATE_BOOLEAN),
            confirmPriceDifferences: filter_var($options['confirm_price_differences'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function toArray(): array
    {
        return [
            'fecha_pago_default' => $this->fechaPago,
            'metodo_pago' => $this->metodoPago,
            'comprobante_url' => $this->comprobanteUrl,
            'observaciones' => $this->observaciones,
            'confirm_real_financial_impact' => $this->confirmImpact,
            'confirm_price_differences' => $this->confirmPriceDifferences,
        ];
    }
}
