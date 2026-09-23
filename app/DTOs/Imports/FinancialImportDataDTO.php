<?php

namespace App\DTOs\Imports;

final readonly class FinancialImportDataDTO
{
    public function __construct(
        public array $modules,
        public HistoricalPaymentOptionsDTO $paymentOptions,
    ) {}

    public static function fromArray(array $modules, array $paymentOptions): self
    {
        return new self(
            modules: $modules,
            paymentOptions: HistoricalPaymentOptionsDTO::fromArray($paymentOptions),
        );
    }

    public function total(): float
    {
        return round(array_sum(array_map(fn (array $module): float => (float) ($module['total'] ?? 0), $this->modules)), 2);
    }

    public function paid(): float
    {
        return round(array_sum(array_map(fn (array $module): float => (float) ($module['paid'] ?? 0), $this->modules)), 2);
    }

    public function balance(): float
    {
        return round(array_sum(array_map(fn (array $module): float => (float) ($module['balance'] ?? 0), $this->modules)), 2);
    }

    public function activeModules(): array
    {
        return array_values(array_filter($this->modules, fn (array $module): bool => (float) ($module['total'] ?? 0) > 0));
    }

    public function toArray(): array
    {
        return [
            'financial_modules' => $this->modules,
            'payment_options' => $this->paymentOptions->toArray(),
        ];
    }
}
