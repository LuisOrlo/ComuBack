<?php

namespace Tests\Unit;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\Modulo;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ModelUtilityMethodsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: CursoAbierto::estaLleno()
     */
    public function test_curso_abierto_esta_lleno()
    {
        $curso = CursoAbierto::factory()->create(['capacidad_maxima' => 20]);
        
        // No lleno
        Matricula::factory()->count(10)->activa()->create(['curso_abierto_id' => $curso->id]);
        $this->assertFalse($curso->estaLleno());

        // Crear 10 más
        Matricula::factory()->count(10)->activa()->create(['curso_abierto_id' => $curso->id]);
        $this->assertTrue($curso->refresh()->estaLleno());
    }

    /**
     * Test: CursoAbierto::hayEspacios()
     */
    public function test_curso_abierto_hay_espacios()
    {
        $curso = CursoAbierto::factory()->create(['capacidad_maxima' => 30]);
        Matricula::factory()->count(20)->activa()->create(['curso_abierto_id' => $curso->id]);

        $this->assertTrue($curso->hayEspacios());
    }

    /**
     * Test: CursoAbierto::obtenerEspaciosDisponibles()
     */
    public function test_curso_abierto_obtener_espacios_disponibles()
    {
        $curso = CursoAbierto::factory()->create(['capacidad_maxima' => 30]);
        Matricula::factory()->count(12)->activa()->create(['curso_abierto_id' => $curso->id]);

        $disponibles = $curso->obtenerEspaciosDisponibles();
        $this->assertEquals(18, $disponibles);
    }

    /**
     * Test: CursoAbierto::getPorcentajeOcupacion()
     */
    public function test_curso_abierto_get_porcentaje_ocupacion()
    {
        $curso = CursoAbierto::factory()->create(['capacidad_maxima' => 100]);
        Matricula::factory()->count(50)->activa()->create(['curso_abierto_id' => $curso->id]);

        $porcentaje = $curso->getPorcentajeOcupacion();
        $this->assertEquals(50.0, $porcentaje);
    }

    /**
     * Test: CursoAbierto::estaVigente()
     */
    public function test_curso_abierto_esta_vigente()
    {
        $vigente = CursoAbierto::factory()->vigente()->create();
        $proximo = CursoAbierto::factory()->proximo()->create();
        $finalizado = CursoAbierto::factory()->finalizado()->create();

        $this->assertTrue($vigente->estaVigente());
        $this->assertFalse($proximo->estaVigente());
        $this->assertFalse($finalizado->estaVigente());
    }

    /**
     * Test: CursoAbierto::esValido()
     */
    public function test_curso_abierto_es_valido()
    {
        $curso = CursoAbierto::factory()->create([
            'nombre_instancia' => 'Grupo A',
            'semestre' => '2026-1',
            'capacidad_maxima' => 30,
        ]);

        $this->assertTrue($curso->esValido());
    }

    /**
     * Test: CursoAbierto::getNombreCompleto()
     */
    public function test_curso_abierto_get_nombre_completo()
    {
        $curso = CursoAbierto::factory()->create(['nombre_instancia' => 'Grupo A']);

        $nombreCompleto = $curso->getNombreCompleto();
        $this->assertStringContainsString('Grupo A', $nombreCompleto);
    }

    /**
     * Test: Nota::esAprobada()
     */
    public function test_nota_es_aprobada()
    {
        $aprobada = Nota::factory()->aprobada()->create(['calificacion' => 3.5]);
        $reprobada = Nota::factory()->reprobada()->create(['calificacion' => 2.5]);

        $this->assertTrue($aprobada->esAprobada());
        $this->assertFalse($reprobada->esAprobada());
    }

    /**
     * Test: Nota::esReprobada()
     */
    public function test_nota_es_reprobada()
    {
        $aprobada = Nota::factory()->aprobada()->create(['calificacion' => 3.5]);
        $reprobada = Nota::factory()->reprobada()->create(['calificacion' => 2.5]);

        $this->assertFalse($aprobada->esReprobada());
        $this->assertTrue($reprobada->esReprobada());
    }

    /**
     * Test: Nota::getDescriptiva()
     */
    public function test_nota_get_descriptiva()
    {
        $excelente = Nota::factory()->excelente()->create();
        $buena = Nota::factory()->buena()->create();
        $regular = Nota::factory()->regular()->create();

        $this->assertEquals('Excelente', $excelente->getDescriptiva());
        $this->assertEquals('Bueno', $buena->getDescriptiva());
        $this->assertEquals('Regular', $regular->getDescriptiva());
    }

    /**
     * Test: Modulo::getDuracionSemanas()
     */
    public function test_modulo_get_duracion_semanas()
    {
        $modulo = Modulo::factory()->create([
            'semana_inicio' => 1,
            'semana_fin' => 4,
        ]);

        $duracion = $modulo->getDuracionSemanas();
        $this->assertEquals(4, $duracion);
    }

    /**
     * Test: Modulo::getPeriodo()
     */
    public function test_modulo_get_periodo()
    {
        $modulo = Modulo::factory()->create([
            'semana_inicio' => 5,
            'semana_fin' => 8,
        ]);

        $periodo = $modulo->getPeriodo();
        $this->assertStringContainsString('5', $periodo);
        $this->assertStringContainsString('8', $periodo);
    }

    /**
     * Test: Modulo::getPonderacionFormato()
     */
    public function test_modulo_get_ponderacion_formato()
    {
        $modulo = Modulo::factory()->create(['ponderacion' => 25.5]);

        $formato = $modulo->getPonderacionFormato();
        $this->assertStringContainsString('25.5', $formato);
    }

    /**
     * Test: Matricula::estaActiva()
     */
    public function test_matricula_esta_activa()
    {
        $activa = Matricula::factory()->activa()->create();
        $completada = Matricula::factory()->completada()->create();
        $retirada = Matricula::factory()->retirada()->create();

        $this->assertTrue($activa->estaActiva());
        $this->assertFalse($completada->estaActiva());
        $this->assertFalse($retirada->estaActiva());
    }

    /**
     * Test: Matricula::getTotalNotas()
     */
    public function test_matricula_get_total_notas()
    {
        $matricula = Matricula::factory()->create();
        Nota::factory()->count(4)->create(['matricula_id' => $matricula->id]);

        $totalNotas = $matricula->getTotalNotas();
        $this->assertEquals(4, $totalNotas);
    }

    /**
     * Test: Matricula::getPromedio()
     */
    public function test_matricula_get_promedio()
    {
        $matricula = Matricula::factory()->create();
        Nota::factory()->create(['matricula_id' => $matricula->id, 'calificacion' => 3.0]);
        Nota::factory()->create(['matricula_id' => $matricula->id, 'calificacion' => 4.0]);
        Nota::factory()->create(['matricula_id' => $matricula->id, 'calificacion' => 5.0]);

        $promedio = $matricula->getPromedio();
        $this->assertEquals(4.0, $promedio);
    }

    /**
     * Test: Matricula::tieneNotas()
     */
    public function test_matricula_tiene_notas()
    {
        $conNotas = Matricula::factory()->create();
        Nota::factory()->create(['matricula_id' => $conNotas->id]);

        $sinNotas = Matricula::factory()->create();

        $this->assertTrue($conNotas->tieneNotas());
        $this->assertFalse($sinNotas->tieneNotas());
    }
}
