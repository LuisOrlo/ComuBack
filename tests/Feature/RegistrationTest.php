<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Persona;
use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Models\SolicitudInscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $catalogo;
    protected $cursoDisponible;
    protected $cursoLleno;
    protected $estudiante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestData();
    }

    private function createTestData()
    {
        // Crear catálogo
        $this->catalogo = CatalogoCurso::create([
            'nombre' => 'Curso Test',
            'categoria' => 'regular',
        ]);

        // Crear curso disponible
        $this->cursoDisponible = CursoAbierto::create([
            'catalogo_id' => $this->catalogo->id,
            'precio_base' => 100.00,
            'capacidad_maxima' => 10,
            'estudiantes_inscritos' => 0,
            'fecha_inicio' => Carbon::now()->addDays(10),
            'estado' => 'confirmado',
            'modalidad' => 'presencial',
        ]);

        // Crear curso lleno
        $this->cursoLleno = CursoAbierto::create([
            'catalogo_id' => $this->catalogo->id,
            'precio_base' => 100.00,
            'capacidad_maxima' => 2,
            'estudiantes_inscritos' => 2,
            'fecha_inicio' => Carbon::now()->addDays(15),
            'estado' => 'confirmado',
            'modalidad' => 'presencial',
        ]);

        // Crear estudiante
        $this->estudiante = Persona::create([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'correo' => 'juan@test.com',
            'tipo' => 'estudiante',
        ]);
    }

    /** @test */
    public function public_can_view_available_courses()
    {
        $response = $this->getJson('/api/v1/catalogo-cursos/disponibles');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }

    /** @test */
    public function available_courses_excludes_full_courses()
    {
        $response = $this->getJson('/api/v1/catalogo-cursos/disponibles');
        $response->assertStatus(200);

        $cursos = collect($response->json('data'));
        $ids = $cursos->pluck('id')->toArray();

        $this->assertContains($this->cursoDisponible->id, $ids);
        $this->assertNotContains($this->cursoLleno->id, $ids);
    }

    /** @test */
    public function student_can_submit_registration()
    {
        $response = $this->postJson('/api/v1/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('academic.solicitudes_inscripcion', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'estado' => 'pendiente_validacion',
        ]);
    }

    /** @test */
    public function registration_validates_full_payment_amount()
    {
        $response = $this->postJson('/api/v1/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 50.00, // Wrong amount
            'tipo_pago' => 'completo',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function registration_validates_course_capacity()
    {
        $response = $this->postJson('/api/v1/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoLleno->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function external_participant_can_register()
    {
        $response = $this->postJson('/api/v1/registrations', [
            'nombres' => 'María',
            'apellidos' => 'González',
            'correo' => 'maria@ext.com',
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(201);
    }
}
