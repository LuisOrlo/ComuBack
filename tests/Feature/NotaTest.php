<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\Matricula;
use App\Models\Modulo;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticatedUser();
    }

    /**
     * Test: Listar notas
     */
    public function test_list_notas()
    {
        Nota::factory()->count(5)->create();

        $response = $this->authenticatedGet('/api/v1/academic/notas');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'calificacion',
                             'observaciones',
                         ]
                     ]
                 ]);

        $this->assertGreaterThanOrEqual(5, count($response->json('data')));
    }

    /**
     * Test: Filtrar notas aprobadas
     */
    public function test_list_notas_aprobadas()
    {
        Nota::factory()->count(3)->aprobada()->create();
        Nota::factory()->count(2)->reprobada()->create();

        $response = $this->authenticatedGet('/api/v1/academic/notas?aprobadas=true');

        $response->assertStatus(200);
        
        $aprobadas = $response->json('data');
        foreach ($aprobadas as $nota) {
            $this->assertGreaterThanOrEqual(3.0, $nota['calificacion']);
        }
    }

    /**
     * Test: Filtrar por estado
     */
    public function test_list_notas_by_estado()
    {
        Nota::factory()->count(3)->aprobada()->create();
        Nota::factory()->count(2)->reprobada()->create();

        $response = $this->authenticatedGet('/api/v1/academic/notas?estado=aprobadas');

        $response->assertStatus(200);
        
        $notas = $response->json('data');
        foreach ($notas as $nota) {
            $this->assertGreaterThanOrEqual(3.0, $nota['calificacion']);
        }
    }

    /**
     * Test: Filtrar por matrícula
     */
    public function test_list_notas_by_matricula()
    {
        $matricula1 = Matricula::factory()->create();
        $matricula2 = Matricula::factory()->create();

        Nota::factory()->count(3)->create(['matricula_id' => $matricula1->id]);
        Nota::factory()->count(2)->create(['matricula_id' => $matricula2->id]);

        $response = $this->authenticatedGet("/api/v1/academic/notas?matricula_id={$matricula1->id}");

        $response->assertStatus(200);
        
        $notas = $response->json('data');
        foreach ($notas as $nota) {
            $this->assertEquals($matricula1->id, $nota['matricula_id']);
        }
    }

    /**
     * Test: Filtrar por módulo
     */
    public function test_list_notas_by_modulo()
    {
        $modulo1 = Modulo::factory()->create();
        $modulo2 = Modulo::factory()->create();

        Nota::factory()->count(3)->create(['modulo_id' => $modulo1->id]);
        Nota::factory()->count(2)->create(['modulo_id' => $modulo2->id]);

        $response = $this->authenticatedGet("/api/v1/academic/notas?modulo_id={$modulo1->id}");

        $response->assertStatus(200);
        
        $notas = $response->json('data');
        foreach ($notas as $nota) {
            $this->assertEquals($modulo1->id, $nota['modulo_id']);
        }
    }

    /**
     * Test: Crear nota
     */
    public function test_create_nota()
    {
        $matricula = Matricula::factory()->create();
        $modulo = Modulo::factory()->create();
        
        $data = [
            'matricula_id' => $matricula->id,
            'modulo_id' => $modulo->id,
            'calificacion' => 4.5,
            'observaciones' => 'Excelente desempeño',
        ];

        $response = $this->authenticatedPost('/api/v1/academic/notas', $data);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'calificacion', 'observaciones'],
                     'message'
                 ]);

        $this->assertDatabaseHas('academic.notas', [
            'calificacion' => 4.5,
        ]);
    }

    /**
     * Test: Crear nota duplicada (debe fallar)
     */
    public function test_create_nota_duplicate()
    {
        $matricula = Matricula::factory()->create();
        $modulo = Modulo::factory()->create();
        
        Nota::factory()->create(['matricula_id' => $matricula->id, 'modulo_id' => $modulo->id]);

        $data = [
            'matricula_id' => $matricula->id,
            'modulo_id' => $modulo->id,
            'calificacion' => 4.0,
        ];

        $response = $this->authenticatedPost('/api/v1/academic/notas', $data);

        $response->assertStatus(422); // Conflict
    }

    /**
     * Test: Ver nota
     */
    public function test_show_nota()
    {
        $nota = Nota::factory()->create();

        $response = $this->authenticatedGet("/api/v1/academic/notas/{$nota->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'calificacion', 'observaciones']
                 ]);

        $this->assertEquals($nota->id, $response->json('data.id'));
    }

    /**
     * Test: Actualizar nota
     */
    public function test_update_nota()
    {
        $nota = Nota::factory()->create(['calificacion' => 3.5]);

        $data = [
            'calificacion' => 4.5,
            'observaciones' => 'Corrección de calificación',
        ];

        $response = $this->authenticatedPut("/api/v1/academic/notas/{$nota->id}", $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('academic.notas', [
            'id' => $nota->id,
            'calificacion' => 4.5,
        ]);
    }

    /**
     * Test: Eliminar nota
     */
    public function test_delete_nota()
    {
        $nota = Nota::factory()->create();

        $response = $this->authenticatedDelete("/api/v1/academic/notas/{$nota->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('academic.notas', ['id' => $nota->id]);
    }

    /**
     * Test: Obtener descripción de nota
     */
    public function test_get_nota_descriptiva()
    {
        $nota = Nota::factory()->excelente()->create(['calificacion' => 4.8]);

        $response = $this->authenticatedGet("/api/v1/academic/notas/{$nota->id}/descriptiva");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id',
                         'calificacion',
                         'descriptiva',
                         'estado',
                         'es_aprobada',
                         'es_reprobada',
                     ]
                 ]);

        $data = $response->json('data');
        $this->assertEquals('4.80', $data['calificacion']);
        $this->assertTrue($data['es_aprobada']);
        $this->assertFalse($data['es_reprobada']);
    }

    /**
     * Test: Validación de rango de calificación
     */
    public function test_create_nota_invalid_range()
    {
        $matricula = Matricula::factory()->create();
        $modulo = Modulo::factory()->create();
        
        $data = [
            'matricula_id' => $matricula->id,
            'modulo_id' => $modulo->id,
            'calificacion' => 10, // Fuera de rango (0-5)
        ];

        $response = $this->authenticatedPost('/api/v1/academic/notas', $data);

        $response->assertStatus(422)
                 ->assertJsonStructure(['message', 'errors']);
    }

    /**
     * Test: Paginación de notas
     */
    public function test_list_notas_pagination()
    {
        Nota::factory()->count(25)->create();

        $response = $this->authenticatedGet('/api/v1/academic/notas?per_page=10');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }
}
