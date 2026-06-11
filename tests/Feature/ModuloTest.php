<?php

namespace Tests\Feature;

use App\Models\Modulo;
use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Nota;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ModuloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar módulos
     */
    public function test_list_modulos()
    {
        Modulo::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/modulos');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'nombre',
                             'descripcion',
                             'semana_inicio',
                             'semana_fin',
                             'ponderacion',
                             'tipo',
                         ]
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar módulos por catálogo
     */
    public function test_list_modulos_by_catalogo()
    {
        $catalogo = CatalogoCurso::factory()->create();
        Modulo::factory()->count(2)->delCatalogo()->create(['catalogo_curso_id' => $catalogo->id]);

        $response = $this->authenticatedGet("/api/v1/academic/modulos?catalogo_curso_id={$catalogo->id}");

        $response->assertStatus(200);
        
        $modulos = $response->json('data');
        foreach ($modulos as $modulo) {
            $this->assertEquals($catalogo->id, $modulo['catalogo_curso_id']);
        }
    }

    /**
     * Test: Filtrar módulos por curso
     */
    public function test_list_modulos_by_curso()
    {
        $curso = CursoAbierto::factory()->create();
        Modulo::factory()->count(3)->delCurso()->create(['curso_abierto_id' => $curso->id]);

        $response = $this->authenticatedGet("/api/v1/academic/modulos?curso_abierto_id={$curso->id}");

        $response->assertStatus(200);
        
        $modulos = $response->json('data');
        foreach ($modulos as $modulo) {
            $this->assertEquals($curso->id, $modulo['curso_abierto_id']);
        }
    }

    /**
     * Test: Crear módulo
     */
    public function test_create_modulo()
    {
        $curso = CursoAbierto::factory()->create();
        
        $data = [
            'nombre' => 'Introducción',
            'descripcion' => 'Conceptos básicos',
            'semana_inicio' => 1,
            'semana_fin' => 4,
            'ponderacion' => 25,
            'curso_abierto_id' => $curso->id,
        ];

        $response = $this->authenticatedPost('/api/v1/academic/modulos', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre', 'semana_inicio', 'semana_fin', 'ponderacion'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.modulos', [
            'nombre' => 'Introducción',
            'semana_inicio' => 1,
            'semana_fin' => 4,
        ]);
    }

    /**
     * Test: Ver módulo
     */
    public function test_show_modulo()
    {
        $modulo = Modulo::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/modulos/{$modulo->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre', 'semana_inicio', 'semana_fin', 'ponderacion']
                 ]);

        $this->assertEquals($modulo->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar módulo
     */
    public function test_update_modulo()
    {
        $modulo = Modulo::factory()->create([
            'nombre' => 'Módulo 1',
            'ponderacion' => 20,
        ]);

        $data = [
            'nombre' => 'Módulo Actualizado',
            'ponderacion' => 30,
        ];

        $response = $this->authenticatedPut("/api/v1/academic/modulos/{$modulo->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.modulos', [
            'id' => $modulo->id,
            'nombre' => 'Módulo Actualizado',
            'ponderacion' => 30,
        ]);
    }

    /**
     * Test: Eliminar módulo
     */
    public function test_delete_modulo()
    {
        $modulo = Modulo::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/modulos/{$modulo->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.modulos', ['id' => $modulo->id]);
    }

    /**
     * Test: Obtener notas de un módulo
     */
    public function test_get_modulo_notas()
    {
        $modulo = Modulo::factory()->create();
        Nota::factory()->count(5)->create(['modulo_id' => $modulo->id]);

        $response = $this->authenticatedGet("/api/v1/academic/modulos/{$modulo->id}/notas");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'calificacion', 'observaciones']
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Obtener estadísticas de un módulo
     */
    public function test_get_modulo_estadisticas()
    {
        $modulo = Modulo::factory()->primero()->create();
        Nota::factory()->count(10)->aprobada()->create(['modulo_id' => $modulo->id]);

        $response = $this->authenticatedGet("/api/v1/academic/modulos/{$modulo->id}/estadisticas");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'nombre',
                         'tipo',
                         'duracion_semanas',
                         'ponderacion',
                         'periodo',
                         'notas_registradas',
                     ]
                 ]);

        $data = $response->json('data');
        $this->assertEquals($modulo->nombre, $data['nombre']);
        $this->assertEquals('personalizado', $data['tipo']);
        $this->assertEquals(4, $data['duracion_semanas']); // semana_fin - semana_inicio + 1
    }

    /**
     * Test: Paginación de módulos
     */
    public function test_list_modulos_pagination()
    {
        Modulo::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/modulos?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
