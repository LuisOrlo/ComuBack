<?php

namespace Tests\Feature;

use App\Models\Horario;
use App\Models\CursoAbierto;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HorarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar horarios
     */
    public function test_list_horarios()
    {
        Horario::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/horarios');

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

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar horarios activos
     */
    public function test_list_horarios_activos()
    {
        Horario::factory()->count(3)->create(['es_activo' => true]);
        Horario::factory()->count(2)->inactivo()->create();

        $response = $this->authenticatedGet('/api/v1/academic/horarios?activos=true');

        $response->assertStatus(200);
        
        $activos = $response->json('data');
        foreach ($activos as $horario) {
            $this->assertTrue($horario['es_activo']);
        }
    }

    /**
     * Test: Filtrar por curso
     */
    public function test_list_horarios_by_curso()
    {
        $curso1 = CursoAbierto::factory()->create();
        $curso2 = CursoAbierto::factory()->create();

        Horario::factory()->create(['curso_abierto_id' => $curso1->id]);
        Horario::factory()->create(['curso_abierto_id' => $curso2->id]);

        $response = $this->authenticatedGet("/api/v1/academic/horarios?curso_abierto_id={$curso1->id}");

        $response->assertStatus(200);
        
        $horarios = $response->json('data');
        foreach ($horarios as $horario) {
            $this->assertEquals($curso1->id, $horario['curso_abierto_id']);
        }
    }

    /**
     * Test: Crear horario
     */
    public function test_create_horario()
    {
        $curso = CursoAbierto::factory()->create();
        
        $data = [
            'curso_abierto_id' => $curso->id,
            'nombre_referencial' => 'Mañana - Salón 101',
            'hora_inicio' => '07:00',
            'hora_fin' => '10:00',
            'dias_semana' => [1, 3, 5],
            'es_activo' => true,
        ];

        $response = $this->authenticatedPost('/api/v1/academic/horarios', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre_referencial', 'hora_inicio', 'hora_fin'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.horarios', [
            'nombre_referencial' => 'Mañana - Salón 101',
            'hora_inicio' => '07:00',
            'hora_fin' => '10:00',
        ]);
    }

    /**
     * Test: Ver horario
     */
    public function test_show_horario()
    {
        $horario = Horario::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/horarios/{$horario->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'nombre_referencial', 'hora_inicio', 'hora_fin']
                 ]);

        $this->assertEquals($horario->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar horario
     */
    public function test_update_horario()
    {
        $horario = Horario::factory()->create([
            'hora_inicio' => '07:00',
            'hora_fin' => '10:00',
        ]);

        $data = [
            'hora_inicio' => '08:00',
            'hora_fin' => '11:00',
        ];

        $response = $this->authenticatedPut("/api/v1/academic/horarios/{$horario->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.horarios', [
            'id' => $horario->id,
            'hora_inicio' => '08:00',
            'hora_fin' => '11:00',
        ]);
    }

    /**
     * Test: Eliminar horario
     */
    public function test_delete_horario()
    {
        $horario = Horario::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/horarios/{$horario->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.horarios', ['id' => $horario->id]);
    }

    /**
     * Test: Obtener matrículas de un horario
     */
    public function test_get_horario_matriculas()
    {
        $horario = Horario::factory()->create();
        // Las matrículas se relacionan a través del curso abierto
        
        $response = $this->authenticatedGet("/api/v1/academic/horarios/{$horario->id}/matriculas");

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Test: Obtener descripción del horario
     */
    public function test_get_horario_descripcion()
    {
        $horario = Horario::factory()->matutino()->create([
            'nombre_referencial' => 'Mañana - Salón A',
        ]);

        $response = $this->authenticatedGet("/api/v1/academic/horarios/{$horario->id}/descripcion");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'nombre_referencial',
                         'periodo',
                         'dias',
                         'descripcion',
                     ]
                 ]);

        $data = $response->json('data');
        $this->assertStringContainsString('Mañana - Salón A', $data['nombre_referencial']);
    }
}
