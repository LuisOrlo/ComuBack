<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Modulo;
use Tests\TestCase;

class CursoModuloDateCoherenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    public function test_creates_course_without_modules(): void
    {
        $response = $this->authenticatedPost('/api/academic/cursos-abiertos', $this->coursePayload([
            'modulos' => [],
        ]));

        $response->assertCreated();
    }

    public function test_rejects_module_outside_course_range(): void
    {
        $response = $this->authenticatedPost('/api/academic/cursos-abiertos', $this->coursePayload([
            'modulos' => [[
                'nombre' => 'Módulo fuera de rango',
                'fecha_inicio' => '2026-09-24',
                'fecha_fin' => '2026-10-14',
            ]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('modulos.0.fecha_fin');
    }

    public function test_rejects_module_with_reversed_dates(): void
    {
        $response = $this->authenticatedPost('/api/academic/cursos-abiertos', $this->coursePayload([
            'modulos' => [[
                'nombre' => 'Módulo inválido',
                'fecha_inicio' => '2026-06-15',
                'fecha_fin' => '2026-06-10',
            ]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('modulos.0.fecha_fin');
    }

    public function test_allows_multiple_modules_within_course_range_and_equal_boundaries(): void
    {
        $response = $this->authenticatedPost('/api/academic/cursos-abiertos', $this->coursePayload([
            'fecha_fin' => '2026-10-31',
            'modulos' => [
                ['nombre' => 'Módulo 1', 'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-06-10'],
                ['nombre' => 'Módulo 2', 'fecha_inicio' => '2026-06-10', 'fecha_fin' => '2026-10-31'],
            ],
        ]));

        $response->assertCreated();
    }

    public function test_rejects_course_edit_that_excludes_existing_module(): void
    {
        $course = $this->createCourse([
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-10-31',
        ]);
        Modulo::create([
            'curso_abierto_id' => $course->id,
            'nombre_modulo' => 'Módulo 1',
            'numero_orden' => 1,
            'fecha_inicio' => '2026-09-24',
            'fecha_fin' => '2026-10-14',
            'precio_base' => 36,
        ]);

        $response = $this->authenticatedPut("/api/academic/cursos-abiertos/{$course->id}", [
            'fecha_fin' => '2026-06-20',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('modulos.0.fecha_fin');
    }

    public function test_does_not_change_persisted_course_state(): void
    {
        $course = $this->createCourse([
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-10-31',
        ]);
        Modulo::create([
            'curso_abierto_id' => $course->id,
            'nombre_modulo' => 'Módulo 1',
            'numero_orden' => 1,
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-10-31',
            'precio_base' => 36,
        ]);

        $this->authenticatedPut("/api/academic/cursos-abiertos/{$course->id}", [
            'nombre_instancia' => 'Curso actualizado',
        ])->assertOk();

        $this->assertDatabaseHas('academic.cursos_abiertos', [
            'id' => $course->id,
            'estado' => 'pendiente',
        ]);
    }

    private function coursePayload(array $overrides = []): array
    {
        $catalog = CatalogoCurso::create([
            'nombre' => 'Catálogo de coherencia',
            'descripcion' => 'Fixture de prueba',
            'creditos' => 1,
            'horas_totales' => 1,
            'categoria' => 'regular',
            'modulos_default' => 0,
            'es_activo' => true,
            'color' => '#000000',
            'imagen' => 'BookOpenIcon',
        ]);

        return array_merge([
            'catalogo_curso_id' => $catalog->id,
            'nombre_instancia' => 'Curso coherente',
            'semestre' => '2026-1',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-06-20',
            'capacidad_maxima' => 18,
            'modalidad' => 'virtual',
            'es_activo' => true,
            'precio_base' => 36,
        ], $overrides);
    }

    private function createCourse(array $overrides = []): CursoAbierto
    {
        $catalog = CatalogoCurso::create([
            'nombre' => 'Catálogo de coherencia',
            'descripcion' => 'Fixture de prueba',
            'creditos' => 1,
            'horas_totales' => 1,
            'categoria' => 'regular',
            'modulos_default' => 0,
            'es_activo' => true,
            'color' => '#000000',
            'imagen' => 'BookOpenIcon',
        ]);

        return CursoAbierto::create(array_merge([
            'catalogo_curso_id' => $catalog->id,
            'nombre_instancia' => 'Curso fixture',
            'semestre' => '2026-1',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-06-20',
            'capacidad_maxima' => 18,
            'modalidad' => 'virtual',
            'es_activo' => true,
            'precio_base' => 36,
        ], $overrides));
    }
}
