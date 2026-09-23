<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Persona;
use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Models\SolicitudInscripcion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Carbon\Carbon;

class RegistrationTest extends TestCase
{
    use DatabaseTransactions;

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
            'catalogo_curso_id' => $this->catalogo->id,
            'precio_base' => 100.00,
            'capacidad_maxima' => 10,
            'estudiantes_inscritos' => 0,
            'fecha_inicio' => Carbon::now()->addDays(10),
            'estado' => 'confirmado',
            'modalidad' => 'presencial',
        ]);

        // Crear curso lleno
        $this->cursoLleno = CursoAbierto::create([
            'catalogo_curso_id' => $this->catalogo->id,
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
    public function test_public_can_view_available_courses()
    {
        $response = $this->getJson('/api/catalogo-cursos/disponibles');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }

    /** @test */
    public function test_available_courses_excludes_full_courses()
    {
        $response = $this->getJson('/api/catalogo-cursos/disponibles');
        $response->assertStatus(200);

        $cursos = collect($response->json('data'));
        $ids = $cursos->pluck('id')->toArray();

        $this->assertContains($this->cursoDisponible->id, $ids);
        $this->assertNotContains($this->cursoLleno->id, $ids);
    }

    /** @test */
    public function test_student_can_submit_registration()
    {
        $this->createAuthenticatedUser();

        $response = $this->postJson('/api/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_cedula_url' => 'https://example.com/cedula.jpg',
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

    public function test_persona_id_allows_null_identity_fields()
    {
        $this->createAuthenticatedUser();

        $response = $this->postJson('/api/registrations', [
            'persona_id' => $this->estudiante->id,
            'nombres' => null,
            'apellidos' => null,
            'correo' => null,
            'cedula' => null,
            'celular' => null,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_cedula_url' => 'https://example.com/cedula.jpg',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(201);
        $errors = $response->json('errors') ?? [];
        $this->assertArrayNotHasKey('nombres', $errors);
        $this->assertArrayNotHasKey('apellidos', $errors);
        $this->assertArrayNotHasKey('correo', $errors);
        $this->assertArrayNotHasKey('cedula', $errors);
        $this->assertArrayNotHasKey('celular', $errors);
    }

    public function test_persona_id_reuses_stored_cedula_document()
    {
        $this->createAuthenticatedUser();
        $this->estudiante->update([
            'cedula_photo_url' => '/storage/cedulas/existente.jpeg',
        ]);

        $response = $this->postJson('/api/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 0,
            'monto_declarado' => 0,
            'tipo_pago' => 'abono',
            'tipo_comprobante' => 'efectivo',
            'fecha_pago_declarada' => Carbon::now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('academic.solicitudes_inscripcion', [
            'persona_id' => $this->estudiante->id,
            'archivo_cedula_url' => '/storage/cedulas/existente.jpeg',
            'monto_solicitado' => 0,
        ]);
    }

    public function test_persona_id_without_stored_cedula_document_requires_document()
    {
        $this->createAuthenticatedUser();

        $response = $this->postJson('/api/registrations', [
            'persona_id' => $this->estudiante->id,
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 0,
            'monto_declarado' => 0,
            'tipo_pago' => 'abono',
            'tipo_comprobante' => 'efectivo',
            'fecha_pago_declarada' => Carbon::now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['archivo_cedula_url', 'archivo_cedula']);
    }

    public function test_public_registration_requires_identity_without_persona_id()
    {
        $response = $this->postJson('/api/registrations', [
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_cedula_url' => 'https://example.com/cedula.jpg',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['nombres', 'apellidos', 'correo', 'tipo_id', 'cedula', 'celular']);
    }

    /** @test */
    public function test_registration_validates_full_payment_amount()
    {
        $response = $this->postJson('/api/registrations', [
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
    public function test_registration_validates_course_capacity()
    {
        $response = $this->postJson('/api/registrations', [
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
    public function test_external_participant_can_register()
    {
        $response = $this->postJson('/api/registrations', [
            'nombres' => 'María',
            'apellidos' => 'González',
            'correo' => 'maria@ext.com',
            'tipo_id' => 'cedula',
            'cedula' => '0912345678',
            'celular' => '0999999999',
            'curso_abierto_id' => $this->cursoDisponible->id,
            'monto_solicitado' => 100.00,
            'tipo_pago' => 'completo',
            'archivo_cedula_url' => 'https://example.com/cedula.jpg',
            'archivo_comprobante_url' => 'https://example.com/comprobante.pdf',
            'tipo_comprobante' => 'transferencia',
            'fecha_pago_declarada' => Carbon::now()->subDay()->toDateString(),
        ]);

        $response->assertStatus(201);
    }
}
