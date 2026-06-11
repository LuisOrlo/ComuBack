<?php

namespace Tests\Feature;

use App\Models\CambioHorario;
use App\Models\Matricula;
use App\Models\CursoAbierto;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CambioHorarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar cambios de horario
     */
    public function test_list_cambios_horario()
    {
        CambioHorario::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/cambios-horario');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'estado',
                             'motivo',
                         ]
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar cambios pendientes
     */
    public function test_list_cambios_horario_pendientes()
    {
        CambioHorario::factory()->count(3)->pendiente()->create();
        CambioHorario::factory()->count(2)->aprobado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/cambios-horario?pendientes=true');

        $response->assertStatus(200);
        
        $pendientes = $response->json('data');
        foreach ($pendientes as $cambio) {
            $this->assertEquals('pendiente', $cambio['estado']);
        }
    }

    /**
     * Test: Filtrar por estado
     */
    public function test_list_cambios_horario_by_estado()
    {
        CambioHorario::factory()->count(2)->pendiente()->create();
        CambioHorario::factory()->count(2)->aprobado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/cambios-horario?estado=pendiente');

        $response->assertStatus(200);
        
        $cambios = $response->json('data');
        foreach ($cambios as $cambio) {
            $this->assertEquals('pendiente', $cambio['estado']);
        }
    }

    /**
     * Test: Crear cambio de horario
     */
    public function test_create_cambio_horario()
    {
        $matricula = Matricula::factory()->create();
        $cursoAntiguo = $matricula->cursoAbierto;
        $cursoNuevo = CursoAbierto::factory()->create();
        
        $data = [
            'matricula_origen_id' => $matricula->id,
            'curso_abierto_antiguo_id' => $cursoAntiguo->id,
            'curso_abierto_nuevo_id' => $cursoNuevo->id,
            'motivo' => 'Conflicto de horarios',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/cambios-horario', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'estado', 'motivo'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.cambios_horarios', [
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Test: Ver cambio de horario
     */
    public function test_show_cambio_horario()
    {
        $cambio = CambioHorario::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/cambios-horario/{$cambio->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'estado', 'motivo']
                 ]);

        $this->assertEquals($cambio->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar cambio de horario
     */
    public function test_update_cambio_horario()
    {
        $cambio = CambioHorario::factory()->pendiente()->create();

        $data = [
            'estado' => 'aprobado',
            'observaciones_admin' => 'Cambio autorizado',
        ];

        $response = $this->authenticatedPut("/api/v1/academic/cambios-horario/{$cambio->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.cambios_horarios', [
            'id' => $cambio->id,
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Test: Eliminar cambio de horario
     */
    public function test_delete_cambio_horario()
    {
        $cambio = CambioHorario::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/cambios-horario/{$cambio->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.cambios_horarios', ['id' => $cambio->id]);
    }

    /**
     * Test: Aprobar cambio de horario
     */
    public function test_approve_cambio_horario()
    {
        $cambio = CambioHorario::factory()->pendiente()->create();

        $response = $this->authenticatedPost("/api/v1/academic/cambios-horario/{$cambio->id}/aprobar");

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.cambios_horarios', [
            'id' => $cambio->id,
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Test: Rechazar cambio de horario
     */
    public function test_reject_cambio_horario()
    {
        $cambio = CambioHorario::factory()->pendiente()->create();

        $data = ['observaciones_admin' => 'No hay disponibilidad'];

        $response = $this->authenticatedPost("/api/v1/academic/cambios-horario/{$cambio->id}/rechazar", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.cambios_horarios', [
            'id' => $cambio->id,
            'estado' => 'rechazado',
        ]);
    }

    /**
     * Test: Completar cambio de horario
     */
    public function test_complete_cambio_horario()
    {
        $cambio = CambioHorario::factory()->aprobado()->create();

        $response = $this->authenticatedPost("/api/v1/academic/cambios-horario/{$cambio->id}/completar");

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.cambios_horarios', [
            'id' => $cambio->id,
            'estado' => 'completado',
        ]);
    }

    /**
     * Test: No permitir cambios en curso que está lleno
     */
    public function test_cannot_change_to_full_course()
    {
        $matricula = Matricula::factory()->create();
        $cursoAntiguo = $matricula->cursoAbierto;
        
        // Crear curso lleno
        $cursoNuevo = CursoAbierto::factory()->lleno()->create();
        Matricula::factory()->count($cursoNuevo->capacidad_maxima)->create(['curso_abierto_id' => $cursoNuevo->id]);
        
        $data = [
            'matricula_origen_id' => $matricula->id,
            'curso_abierto_antiguo_id' => $cursoAntiguo->id,
            'curso_abierto_nuevo_id' => $cursoNuevo->id,
            'motivo' => 'Cambio solicitado',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/cambios-horario', $data);

        // Debería rechazar porque el curso está lleno
        $response->assertStatus(422);
    }

    /**
     * Test: Paginación de cambios
     */
    public function test_list_cambios_horario_pagination()
    {
        CambioHorario::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/cambios-horario?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
