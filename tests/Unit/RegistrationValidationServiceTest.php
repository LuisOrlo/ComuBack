<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Services\RegistrationValidationService;
use App\Services\PaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class RegistrationValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $paymentService;
    protected $curso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RegistrationValidationService::class);
        $this->paymentService = app(PaymentVerificationService::class);
        $this->createTestData();
    }

    private function createTestData()
    {
        // Crear catálogo
        $catalogo = CatalogoCurso::create([
            'nombre' => 'Curso Test',
            'categoria' => 'regular',
        ]);

        // Crear curso con capacidad
        $this->curso = CursoAbierto::create([
            'catalogo_id' => $catalogo->id,
            'precio_base' => 100.00,
            'capacidad_maxima' => 10,
            'estudiantes_inscritos' => 5,
            'fecha_inicio' => Carbon::now()->addDays(10),
            'estado' => 'confirmado',
            'modalidad' => 'presencial',
        ]);
    }

    /** @test */
    public function validates_course_capacity_available()
    {
        $result = $this->service->validar(
            $this->curso->id,
            'est-001',
            null,
            100.00,
            'completo'
        );

        $this->assertTrue($result['valido']);
        $this->assertEquals(5, $result['capacidad_disponible']);
    }

    /** @test */
    public function validates_full_payment_amount()
    {
        $result = $this->service->validar(
            $this->curso->id,
            'est-001',
            null,
            100.00, // Correct amount
            'completo'
        );

        $this->assertTrue($result['valido']);
    }

    /** @test */
    public function rejects_wrong_full_payment_amount()
    {
        $result = $this->service->validar(
            $this->curso->id,
            'est-001',
            null,
            50.00, // Wrong amount
            'completo'
        );

        $this->assertFalse($result['valido']);
    }

    /** @test */
    public function validates_partial_payment_amount()
    {
        $result = $this->service->validar(
            $this->curso->id,
            'est-001',
            null,
            50.00, // Valid abono
            'abono'
        );

        $this->assertTrue($result['valido']);
    }

    /** @test */
    public function payment_validation_accepts_valid_proof()
    {
        $result = $this->paymentService->validar(
            'https://example.com/comprobante.pdf',
            'transferencia',
            Carbon::now()->subDay()->toDateString(),
            100.00,
            100.00
        );

        $this->assertTrue($result['valido']);
    }

    /** @test */
    public function payment_validation_rejects_future_date()
    {
        $result = $this->paymentService->validar(
            'https://example.com/comprobante.pdf',
            'transferencia',
            Carbon::now()->addDay()->toDateString(),
            100.00,
            100.00
        );

        $this->assertFalse($result['valido']);
    }

    /** @test */
    public function payment_validation_rejects_missing_url()
    {
        $result = $this->paymentService->validar(
            null,
            'transferencia',
            Carbon::now()->subDay()->toDateString(),
            100.00,
            100.00
        );

        $this->assertFalse($result['valido']);
    }
}
