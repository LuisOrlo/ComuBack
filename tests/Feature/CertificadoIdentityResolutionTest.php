<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Persona;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificadoIdentityResolutionTest extends TestCase
{
    public function test_panel_resolves_persona_from_imported_matricula_without_solicitud(): void
    {
        $this->createAuthenticatedUser();
        $cedula = sprintf('8%09d', random_int(0, 999999999));
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'nombres' => 'ANA SOFIA',
            'apellidos' => 'TORRES VEGA',
            'cedula' => $cedula,
            'es_activo' => true,
        ]);
        $course = $this->makeCourse('hgfhfgfhg');
        $matricula = Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $course->id,
            'precio_total_legacy' => 0,
            'tipo_pago' => 'completo',
            'estado' => Matricula::ESTADO_ACTIVO,
            'solicitud_inscripcion_id' => null,
        ]);

        $response = $this->getJson('/api/academic/certificados/panel-estudiantes?per_page=100');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('matricula_id', (string) $matricula->id);
        $this->assertNotNull($row);
        $this->assertSame((string) $persona->id, (string) $row['persona_id']);
        $this->assertSame('ANA SOFIA', $row['nombres']);
        $this->assertSame('TORRES VEGA', $row['apellidos']);
        $this->assertSame($cedula, $row['cedula']);
    }

    public function test_store_emits_certificate_from_imported_matricula_without_solicitud(): void
    {
        $this->createAuthenticatedUser();
        Storage::fake();
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'nombres' => 'ESTUDIANTE',
            'apellidos' => 'IMPORTADO',
            'cedula' => null,
            'es_activo' => true,
        ]);
        $course = $this->makeCourse('Curso histórico');
        $matricula = Matricula::create([
            'estudiante_id' => $persona->id,
            'curso_abierto_id' => $course->id,
            'precio_total_legacy' => 0,
            'tipo_pago' => 'completo',
            'estado' => Matricula::ESTADO_ACTIVO,
            'solicitud_inscripcion_id' => null,
        ]);

        $response = $this->post('/api/academic/certificados', [
            'matricula_id' => $matricula->id,
            'pdf' => UploadedFile::fake()->create('certificado.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.estudiante_id', (string) $persona->id);
        $response->assertJsonPath('data.curso_abierto_id', (string) $course->id);
    }

    private function makeCourse(string $instance): CursoAbierto
    {
        $catalog = CatalogoCurso::create([
            'categoria' => 'regular',
            'nombre' => 'Certificados test',
            'descripcion' => 'Fixture de certificados',
            'modulos_default' => 1,
            'creditos' => 1,
            'horas_totales' => 1,
            'es_activo' => true,
        ]);

        return CursoAbierto::create([
            'catalogo_curso_id' => $catalog->id,
            'modalidad' => 'virtual',
            'capacidad_maxima' => 10,
            'precio_base' => 0,
            'es_personalizado' => false,
            'es_activo' => true,
            'fecha_inicio' => now()->subMonth(),
            'fecha_fin' => now()->addMonth(),
            'nombre_instancia' => $instance,
        ]);
    }
}
