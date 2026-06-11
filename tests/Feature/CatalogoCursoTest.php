<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CatalogoCursoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar catálogos de cursos
     */
    public function test_list_catalogo_cursos()
    {
        CatalogoCurso::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/catalogos-cursos');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'codigo',
                             'nombre',
                             'descripcion',
                             'creditos',
                             'horas_totales',
                             'modulos_default',
                             'es_activo',
                             'categoria',
                         ]
                     ],
                     'message'
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Listar catálogos activos
     */
    public function test_list_catalogo_cursos_activos()
    {
        CatalogoCurso::factory()->count(3)->create(['es_activo' => true]);
        CatalogoCurso::factory()->count(2)->inactivo()->create();

        $response = $this->authenticatedGet('/api/v1/academic/catalogos-cursos?activos=true');

        $response->assertStatus(200);
        
        $activos = $response->json('data');
        foreach ($activos as $curso) {
            $this->assertTrue($curso['es_activo']);
        }
    }

    /**
     * Test: Filtrar catálogos por categoría
     */
    public function test_list_catalogo_cursos_by_categoria()
    {
        CatalogoCurso::factory()->count(3)->regular()->create();
        CatalogoCurso::factory()->count(2)->personalizado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/catalogos-cursos?categoria=regular');

        $response->assertStatus(200);
        
        $regulares = $response->json('data');
        foreach ($regulares as $curso) {
            $this->assertEquals('regular', $curso['categoria']);
        }
    }

    /**
     * Test: Buscar catálogo por nombre
     */
    public function test_search_catalogo_cursos()
    {
        CatalogoCurso::factory()->create([
            'codigo' => 'MAT-101',
            'nombre' => 'Cálculo I'
        ]);
        CatalogoCurso::factory()->count(3)->create();

        $response = $this->authenticatedGet('/api/v1/academic/catalogos-cursos?buscar=Cálculo');

        $response->assertStatus(200);
        
        $resultados = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($resultados));
        $this->assertStringContainsString('Cálculo', $resultados[0]['nombre']);
    }

    /**
     * Test: Crear catálogo de curso
     */
    public function test_create_catalogo_curso()
    {
        $data = [
            'programa_id' => fake()->uuid(),
            'codigo' => 'MAT-101',
            'nombre' => 'Cálculo I',
            'descripcion' => 'Introducción al cálculo',
            'creditos' => 3,
            'horas_totales' => 48,
            'modulos_default' => 4,
            'categoria' => 'regular',
            'es_activo' => true,
        ];

        $response = $this->authenticatedPost('/api/v1/academic/catalogos-cursos', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'codigo',
                         'nombre',
                         'creditos',
                         'horas_totales',
                         'modulos_default',
                         'es_activo',
                     ],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.catalogo_cursos', [
            'codigo' => 'MAT-101',
            'nombre' => 'Cálculo I',
        ]);
    }

    /**
     * Test: Crear catálogo con validación fallida
     */
    public function test_create_catalogo_curso_invalid()
    {
        $data = [
            'codigo' => '', // Vacío - debe fallar
            'nombre' => 'Cálculo I',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/catalogos-cursos', $data);

        $response->assertStatus(422)
                 ->assertJsonStructure([
                     'message',
                     'errors'
                 ]);
    }

    /**
     * Test: Ver catálogo de curso
     */
    public function test_show_catalogo_curso()
    {
        $catalogo = CatalogoCurso::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/catalogos-cursos/{$catalogo->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'codigo',
                         'nombre',
                         'descripcion',
                         'creditos',
                         'horas_totales',
                         'modulos_default',
                         'es_activo',
                         'categoria',
                     ]
                 ]);

        $this->assertEquals($catalogo->id, $response->json('data.id'));
    }

    /**
     * Test: Ver catálogo inexistente
     */
    public function test_show_catalogo_curso_not_found()
    {
        $fakeId = fake()->uuid();

        $response = $this->authenticatedGet("/api/v1/academic/catalogos-cursos/{$fakeId}");

        $response->assertStatus(404);
    }

    /**
     * Test: Actualizar catálogo de curso
     */
    public function test_update_catalogo_curso()
    {
        $catalogo = CatalogoCurso::factory()->create([
            'nombre' => 'Cálculo I',
            'creditos' => 3,
        ]);

        $data = [
            'nombre' => 'Cálculo Avanzado',
            'creditos' => 4,
        ];

        $response = $this->authenticatedPut("/api/v1/academic/catalogos-cursos/{$catalogo->id}", $data);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre', 'creditos'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.catalogo_cursos', [
            'id' => $catalogo->id,
            'nombre' => 'Cálculo Avanzado',
            'creditos' => 4,
        ]);
    }

    /**
     * Test: Eliminar catálogo de curso
     */
    public function test_delete_catalogo_curso()
    {
        $catalogo = CatalogoCurso::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/catalogos-cursos/{$catalogo->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.catalogo_cursos', [
            'id' => $catalogo->id,
        ]);
    }

    /**
     * Test: Paginación de catálogos
     */
    public function test_list_catalogo_cursos_pagination()
    {
        CatalogoCurso::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/catalogos-cursos?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }

    /**
     * Test: Requiere autenticación
     */
    public function test_requires_authentication()
    {
        $response = $this->getJson('/api/v1/academic/catalogos-cursos');

        $response->assertStatus(401);
    }
}
