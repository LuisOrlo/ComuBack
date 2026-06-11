<?php

namespace Tests\Feature;

use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Models\Horario;
use App\Models\Modulo;
use App\Models\Matricula;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class CursoAbiertoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar cursos abiertos
     */
    public function test_list_cursos_abiertos()
    {
        CursoAbierto::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'nombre_instancia',
                             'semestre',
                             'fecha_inicio',
                             'fecha_fin',
                             'capacidad_maxima',
                             'es_activo',
                         ]
                     ],
                     'message'
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar cursos activos
     */
    public function test_list_cursos_abiertos_activos()
    {
        CursoAbierto::factory()->count(3)->create(['es_activo' => true]);
        CursoAbierto::factory()->count(2)->inactivo()->create();

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos?activos=true');

        $response->assertStatus(200);
        
        $activos = $response->json('data');
        foreach ($activos as $curso) {
            $this->assertTrue($curso['es_activo']);
        }
    }

    /**
     * Test: Filtrar cursos vigentes
     */
    public function test_list_cursos_abiertos_vigentes()
    {
        // Vigente
        CursoAbierto::factory()->vigente()->create();
        
        // Próximo
        CursoAbierto::factory()->proximo()->create();
        
        // Finalizado
        CursoAbierto::factory()->finalizado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos?vigentes=true');

        $response->assertStatus(200);
        
        $vigentes = $response->json('data');
        foreach ($vigentes as $curso) {
            $this->assertTrue($curso['es_activo']);
            // Verificar que el curso está en rango de fechas
            $inicio = Carbon::parse($curso['fecha_inicio']);
            $fin = Carbon::parse($curso['fecha_fin']);
            $ahora = Carbon::now();
            
            $this->assertTrue($inicio <= $ahora && $ahora <= $fin);
        }
    }

    /**
     * Test: Filtrar por catálogo
     */
    public function test_list_cursos_abiertos_by_catalogo()
    {
        $catalogo1 = CatalogoCurso::factory()->create();
        $catalogo2 = CatalogoCurso::factory()->create();

        CursoAbierto::factory()->create(['catalogo_curso_id' => $catalogo1->id]);
        CursoAbierto::factory()->create(['catalogo_curso_id' => $catalogo2->id]);

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos?catalogo_curso_id={$catalogo1->id}");

        $response->assertStatus(200);
        
        $cursos = $response->json('data');
        foreach ($cursos as $curso) {
            $this->assertEquals($catalogo1->id, $curso['catalogo_curso_id']);
        }
    }

    /**
     * Test: Filtrar por semestre
     */
    public function test_list_cursos_abiertos_by_semestre()
    {
        CursoAbierto::factory()->create(['semestre' => '2026-1']);
        CursoAbierto::factory()->create(['semestre' => '2026-2']);

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos?semestre=2026-1');

        $response->assertStatus(200);
        
        $cursos = $response->json('data');
        foreach ($cursos as $curso) {
            $this->assertEquals('2026-1', $curso['semestre']);
        }
    }

    /**
     * Test: Buscar curso por nombre
     */
    public function test_search_cursos_abiertos()
    {
        CursoAbierto::factory()->create(['nombre_instancia' => 'Grupo A']);
        CursoAbierto::factory()->count(3)->create();

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos?buscar=Grupo');

        $response->assertStatus(200);
        
        $resultados = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($resultados));
    }

    /**
     * Test: Crear curso abierto
     */
    public function test_create_curso_abierto()
    {
        $catalogo = CatalogoCurso::factory()->create();
        
        $data = [
            'catalogo_curso_id' => $catalogo->id,
            'nombre_instancia' => 'Grupo A',
            'semestre' => '2026-1',
            'fecha_inicio' => Carbon::now()->addDays(1)->toDateString(),
            'fecha_fin' => Carbon::now()->addDays(60)->toDateString(),
            'capacidad_maxima' => 30,
            'docente_id' => fake()->uuid(),
            'es_activo' => true,
            'observaciones' => 'Test course',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/cursos-abiertos', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'nombre_instancia',
                         'semestre',
                         'capacidad_maxima',
                         'es_activo',
                     ],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.cursos_abiertos', [
            'nombre_instancia' => 'Grupo A',
            'semestre' => '2026-1',
        ]);
    }

    /**
     * Test: Ver curso abierto
     */
    public function test_show_curso_abierto()
    {
        $curso = CursoAbierto::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos/{$curso->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'nombre_instancia',
                         'semestre',
                         'fecha_inicio',
                         'fecha_fin',
                         'capacidad_maxima',
                         'es_activo',
                     ]
                 ]);

        $this->assertEquals($curso->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar curso abierto
     */
    public function test_update_curso_abierto()
    {
        $curso = CursoAbierto::factory()->create([
            'nombre_instancia' => 'Grupo A',
            'capacidad_maxima' => 30,
        ]);

        $data = [
            'nombre_instancia' => 'Grupo B',
            'capacidad_maxima' => 40,
        ];

        $response = $this->authenticatedPut("/api/v1/academic/cursos-abiertos/{$curso->id}", $data);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre_instancia', 'capacidad_maxima'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.cursos_abiertos', [
            'id' => $curso->id,
            'nombre_instancia' => 'Grupo B',
            'capacidad_maxima' => 40,
        ]);
    }

    /**
     * Test: Eliminar curso abierto
     */
    public function test_delete_curso_abierto()
    {
        $curso = CursoAbierto::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/cursos-abiertos/{$curso->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.cursos_abiertos', [
            'id' => $curso->id,
        ]);
    }

    /**
     * Test: Obtener horarios de un curso
     */
    public function test_get_curso_horarios()
    {
        $curso = CursoAbierto::factory()->create();
        Horario::factory()->count(3)->create(['curso_abierto_id' => $curso->id]);

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos/{$curso->id}/horarios");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'nombre_referencial',
                             'hora_inicio',
                             'hora_fin',
                             'es_activo',
                         ]
                     ]
                 ]);

        $this->assertEquals(3, count($response->json('data')));
    }

    /**
     * Test: Obtener matrículas de un curso
     */
    public function test_get_curso_matriculas()
    {
        $curso = CursoAbierto::factory()->create();
        Matricula::factory()->count(5)->create(['curso_abierto_id' => $curso->id]);

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos/{$curso->id}/matriculas");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'estudiante_id',
                             'estado',
                             'fecha_inicio',
                             'fecha_fin',
                         ]
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Obtener módulos de un curso
     */
    public function test_get_curso_modulos()
    {
        $curso = CursoAbierto::factory()->create();
        Modulo::factory()->count(4)->create(['curso_abierto_id' => $curso->id]);

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos/{$curso->id}/modulos");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'nombre',
                             'semana_inicio',
                             'semana_fin',
                             'ponderacion',
                         ]
                     ]
                 ]);

        $this->assertEquals(4, count($response->json('data')));
    }

    /**
     * Test: Obtener estadísticas de un curso
     */
    public function test_get_curso_estadisticas()
    {
        $curso = CursoAbierto::factory()->lleno()->create(['capacidad_maxima' => 30]);
        Matricula::factory()->count(20)->create(['curso_abierto_id' => $curso->id, 'estado' => 'activo']);

        $response = $this->authenticatedGet("/api/v1/academic/cursos-abiertos/{$curso->id}/estadisticas");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'nombre',
                         'matriculas_totales',
                         'espacios_disponibles',
                         'porcentaje_ocupacion',
                         'esta_lleno',
                         'esta_vigente',
                     ]
                 ]);

        $data = $response->json('data');
        $this->assertEquals(20, $data['matriculas_totales']);
        $this->assertEquals(10, $data['espacios_disponibles']);
        $this->assertLessThanOrEqual(100, $data['porcentaje_ocupacion']);
    }

    /**
     * Test: Requiere autenticación
     */
    public function test_requires_authentication()
    {
        $response = $this->getJson('/api/v1/academic/cursos-abiertos');

        $response->assertStatus(401);
    }

    /**
     * Test: Paginación
     */
    public function test_list_cursos_abiertos_pagination()
    {
        CursoAbierto::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/cursos-abiertos?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
