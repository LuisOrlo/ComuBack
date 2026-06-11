<?php

namespace Tests\Feature;

use App\Models\TrasladoModulo;
use App\Models\Matricula;
use App\Models\Modulo;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TrasladoModuloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar traslados de módulo
     */
    public function test_list_traslados_modulo()
    {
        TrasladoModulo::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/traslados-modulo');

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
     * Test: Filtrar traslados pendientes
     */
    public function test_list_traslados_modulo_pendientes()
    {
        TrasladoModulo::factory()->count(3)->pendiente()->create();
        TrasladoModulo::factory()->count(2)->aprobado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/traslados-modulo?pendientes=true');

        $response->assertStatus(200);
        
        $pendientes = $response->json('data');
        foreach ($pendientes as $traslado) {
            $this->assertEquals('pendiente', $traslado['estado']);
        }
    }

    /**
     * Test: Filtrar por estado
     */
    public function test_list_traslados_modulo_by_estado()
    {
        TrasladoModulo::factory()->count(2)->pendiente()->create();
        TrasladoModulo::factory()->count(2)->rechazado()->create();

        $response = $this->authenticatedGet('/api/v1/academic/traslados-modulo?estado=pendiente');

        $response->assertStatus(200);
        
        $traslados = $response->json('data');
        foreach ($traslados as $traslado) {
            $this->assertEquals('pendiente', $traslado['estado']);
        }
    }

    /**
     * Test: Crear traslado de módulo
     */
    public function test_create_traslado_modulo()
    {
        $matricula = Matricula::factory()->create();
        $moduloAntiguo = Modulo::factory()->create();
        $moduloNuevo = Modulo::factory()->create();
        
        $data = [
            'matricula_origen_id' => $matricula->id,
            'modulo_antiguo_id' => $moduloAntiguo->id,
            'modulo_nuevo_id' => $moduloNuevo->id,
            'motivo' => 'Necesito cambiar de grupo',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/traslados-modulo', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'estado', 'motivo'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.traslados_modulos', [
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Test: Ver traslado de módulo
     */
    public function test_show_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/traslados-modulo/{$traslado->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'estado', 'motivo']
                 ]);

        $this->assertEquals($traslado->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar traslado de módulo
     */
    public function test_update_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->pendiente()->create();

        $data = [
            'estado' => 'aprobado',
            'observaciones_admin' => 'Cambio autorizado',
        ];

        $response = $this->authenticatedPut("/api/v1/academic/traslados-modulo/{$traslado->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.traslados_modulos', [
            'id' => $traslado->id,
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Test: Eliminar traslado de módulo
     */
    public function test_delete_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/traslados-modulo/{$traslado->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.traslados_modulos', ['id' => $traslado->id]);
    }

    /**
     * Test: Aprobar traslado de módulo
     */
    public function test_approve_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->pendiente()->create();

        $response = $this->authenticatedPost("/api/v1/academic/traslados-modulo/{$traslado->id}/aprobar");

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.traslados_modulos', [
            'id' => $traslado->id,
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Test: Rechazar traslado de módulo
     */
    public function test_reject_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->pendiente()->create();

        $data = ['observaciones_admin' => 'No hay cupo disponible'];

        $response = $this->authenticatedPost("/api/v1/academic/traslados-modulo/{$traslado->id}/rechazar", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.traslados_modulos', [
            'id' => $traslado->id,
            'estado' => 'rechazado',
        ]);
    }

    /**
     * Test: Completar traslado de módulo
     */
    public function test_complete_traslado_modulo()
    {
        $traslado = TrasladoModulo::factory()->aprobado()->create();

        $response = $this->authenticatedPost("/api/v1/academic/traslados-modulo/{$traslado->id}/completar");

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.traslados_modulos', [
            'id' => $traslado->id,
            'estado' => 'completado',
        ]);
    }

    /**
     * Test: No permitir traslado a módulo de otro curso
     */
    public function test_cannot_transfer_to_different_course()
    {
        $matricula = Matricula::factory()->create();
        
        // Módulos de cursos diferentes
        $modulo1 = Modulo::factory()->create();
        $modulo2 = Modulo::factory()->create(); // De otro curso
        
        $data = [
            'matricula_origen_id' => $matricula->id,
            'modulo_antiguo_id' => $modulo1->id,
            'modulo_nuevo_id' => $modulo2->id,
            'motivo' => 'Cambio solicitado',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/traslados-modulo', $data);

        // Debería rechazar si los módulos no son del mismo curso
        if ($modulo1->curso_abierto_id !== $modulo2->curso_abierto_id) {
            $response->assertStatus(422);
        }
    }

    /**
     * Test: No permitir traslado a módulo ya cursado
     */
    public function test_cannot_transfer_to_past_module()
    {
        $matricula = Matricula::factory()->create();
        
        // Módulo pasado (semanas 1-4)
        $moduloPasado = Modulo::factory()->primero()->create();
        
        // Módulo actual (semanas 5-8)
        $moduloActual = Modulo::factory()->segundo()->create();
        
        $data = [
            'matricula_origen_id' => $matricula->id,
            'modulo_antiguo_id' => $moduloActual->id,
            'modulo_nuevo_id' => $moduloPasado->id,
            'motivo' => 'Cambio solicitado',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/traslados-modulo', $data);

        // Debería rechazar porque el módulo ya pasó
        $response->assertStatus(422);
    }

    /**
     * Test: Paginación de traslados
     */
    public function test_list_traslados_modulo_pagination()
    {
        TrasladoModulo::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/traslados-modulo?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
