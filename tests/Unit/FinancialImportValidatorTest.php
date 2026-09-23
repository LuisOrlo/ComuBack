<?php

namespace Tests\Unit;

use App\Services\Imports\FinancialImportValidator;
use PHPUnit\Framework\TestCase;

class FinancialImportValidatorTest extends TestCase
{
    private FinancialImportValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FinancialImportValidator();
    }

    public function test_parses_common_decimal_formats_without_float_comparison_logic(): void
    {
        $this->assertSame(72.0, $this->validator->parseAmount('72'));
        $this->assertSame(72.0, $this->validator->parseAmount('72,00'));
        $this->assertSame(72.0, $this->validator->parseAmount('$72.00'));
        $this->assertSame(1234.56, $this->validator->parseAmount('1.234,56'));
    }

    public function test_accepts_zero_and_tolerance(): void
    {
        $errors = $this->validator->validateOptions([
            'enabled' => true,
            'confirm_real_financial_impact' => true,
            'groups' => [[
                'external_group_key' => 'module_1',
                'total_column' => 'TOTAL',
                'paid_column' => 'ABONO',
                'balance_column' => 'SALDO',
                'modulo_id' => '00000000-0000-0000-0000-000000000001',
            ]],
            'payment_options' => [
                'fecha_pago_default' => now()->subDay()->toDateString(),
                'metodo_pago' => 'efectivo',
                'confirm_price_differences' => true,
            ],
        ], true, '00000000-0000-0000-0000-000000000002', ['TOTAL', 'ABONO', 'SALDO']);

        $this->assertSame([], $errors);
    }

    public function test_rejects_invalid_method_and_future_date(): void
    {
        $errors = $this->validator->validateOptions([
            'enabled' => true,
            'confirm_real_financial_impact' => true,
            'groups' => [],
            'payment_options' => [
                'fecha_pago_default' => now()->addDay()->toDateString(),
                'metodo_pago' => 'cheque',
            ],
        ], true, '00000000-0000-0000-0000-000000000002', []);

        $codes = array_column($errors, 'code');
        $this->assertContains('PAYMENT_METHOD_REQUIRED', $codes);
        $this->assertContains('PAYMENT_DATE_IN_FUTURE', $codes);
        $this->assertContains('MODULE_MAPPING_REQUIRED', $codes);
    }
}
