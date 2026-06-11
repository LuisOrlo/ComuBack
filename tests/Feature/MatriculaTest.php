<?php

namespace Tests\Feature;

use App\Models\Matricula;
use App\Models\CursoAbierto;
use App\Models\Horario;
use App\Models\Nota;
use App\Models\Modulo;
use App\Models\CambioHorario;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class MatriculaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar matrículas
     */
    public function test_list_matriculas()
    {
        Matricula::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/matriculas');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'estudiante_id',
                             'curso_abierto_id',
                             'estado',
                             'fecha_inicio',
                             'fecha_fin',
                         ]
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar matrículas activas
     */
    public function test_list_matriculas_activas()
    {
        Matricula::factory()->count(3)->activa()->create();
        Matricula::factory()->count(2)->completada()->create();

        $response = $this->authenticatedGet('/api/v1/academic/matriculas?activas=true');

        $response->assertStatus(200);
        
        $activas = $response->json('data');
        foreach ($activas as $matricula) {
            $this->assertEquals('activo', $matricula['estado']);
        }
    }

    /**
     * Test: Filtrar por estado
     */
    public function test_list_matriculas_by_estado()
    {
        Matricula::factory()->count(2)->activa()->create();
        Matricula::factory()->count(2)->completada()->create();

        $response = $this->authenticatedGet('/api/v1/academic/matriculas?estado=activo');

        $response->assertStatus(200);
        
        $matriculas = $response->json('data');
        foreach ($matriculas as $matricula) {
            $this->assertEquals('activo', $matricula['estado']);
        }
    }

    /**
     * Test: Filtrar por estudiante
     */
    public function test_list_matriculas_by_estudiante()
    {
        $estudianteId = fake()->uuid();
        Matricula::factory()->count(3)->create(['estudiante_id' => $estudianteId]);
        Matricula::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/matriculas?estudiante_id={$estudianteId}");

        $response->assertStatus(200);
        
        $matriculas = $response->json('data');
        foreach ($matriculas as $matricula) {
            $this->assertEquals($estudianteId, $matricula['estudiante_id']);
        }
    }

    /**
     * Test: Crear matrícula
     */
    public function test_create_matricula()
    {
        $curso = CursoAbierto::factory()->create();
        $horario = Horario::factory()->create(['curso_abierto_id' => $curso->id]);
        
        $data = [
            'estudiante_id' => fake()->uuid(),
            'curso_abierto_id' => $curso->id,
            'horario_id' => $horario->id,
            'estado' => 'activo',
            'fecha_inicio' => Carbon::now()->toDateString(),
            'fecha_fin' => Carbon::now()->addWeeks(12)->toDateString(),
            'observaciones' => 'Test matrícula',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/matriculas', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'estudiante_id', 'curso_abierto_id', 'estado'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.matriculas', [
            'estado' => 'activo',
        ]);
    }

    /**
     * Test: Ver matrícula
     */
    public function test_show_matricula()
    {
        $matricula = Matricula::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/matriculas/{$matricula->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'estudiante_id', 'curso_abierto_id', 'estado']
                 ]);

        $this->assertEquals($matricula->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar matrícula
     */
    public function test_update_matricula()
    {
        $matricula = Matricula::factory()->activa()->create();

        $data = [
            'estado' => 'completado',
            'observaciones' => 'Curso completado satisfactoriamente',
        ];

        $response = $this->authenticatedPut("/api/v1/academic/matriculas/{$matricula->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.matriculas', [
            'id' => $matricula->id,
            'estado' => 'completado',
        ]);
    }

    /**
     * Test: Eliminar matrícula
     */
    public function test_delete_matricula()
    {
        $matricula = Matricula::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/matriculas/{$matricula->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.matriculas', ['id' => $matricula->id]);
    }

    /**
     * Test: Obtener notas de una matrícula
     */
    public function test_get_matricula_notas()
    {
        $matricula = Matricula::factory()->create();
        Nota::factory()->count(4)->create(['matricula_id' => $matricula->id]);

        $response = $this->authenticatedGet("/api/v1/academic/matriculas/{$matricula->id}/notas");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'calificacion', 'modulo_id']
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(4, count($response->json('data')));
    }

    /**
     * Test: Obtener calificaciones de una matrícula
     */
    public function test_get_matricula_calificaciones()
    {
        $matricula = Matricula::factory()->create();
        $modulo1 = Modulo::factory()->create();
        $modulo2 = Modulo::factory()->create();
        
        Nota::factory()->aprobada()->create(['matricula_id' => $matricula->id, 'modulo_id' => $modulo1->id]);
        Nota::factory()->excelente()->create(['matricula_id' => $matricula->id, 'modulo_id' => $modulo2->id]);

        $response = $this->authenticatedGet("/api/v1/academic/matriculas/{$matricula->id}/calificaciones");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'estudiante',
                         'curso',
                         'promedio_simple',
                         'promedio_ponderado',
                         'total_notas_registradas',
                         'total_modulos',
                         'todas_notas_registradas',
                         'estado',
                     ]
                 ]);

        $data = $response->json('data');
        $this->assertEquals($matricula->id, $data['id']);
        $this->assertEquals(2, $data['total_notas_registradas']);
    }

    /**
     * Test: Obtener cambios de horario de una matrícula
     */
    public function test_get_matricula_cambios_horario()
    {
        $matricula = Matricula::factory()->create();
        CambioHorario::factory()->count(2)->create(['matricula_origen_id' => $matricula->id]);

        $response = $this->authenticatedGet("/api/v1/academic/matriculas/{$matricula->id}/cambios-horario");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'estado', 'motivo']
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    /**
     * Test: Paginación de matrículas
     */
    public function test_list_matriculas_pagination()
    {
        Matricula::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/matriculas?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
