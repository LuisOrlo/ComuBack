<?php

namespace Tests\Unit;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\Modulo;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class ModelScopesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Scope Activos en CursoAbierto
     */
    public function test_scope_activos_curso_abierto()
    {
        CursoAbierto::factory()->count(3)->create(['es_activo' => true]);
        CursoAbierto::factory()->count(2)->inactivo()->create();

        $activos = CursoAbierto::activos()->get();

        $this->assertCount(3, $activos);
        foreach ($activos as $curso) {
            $this->assertTrue($curso->es_activo);
        }
    }

    /**
     * Test: Scope Vigentes en CursoAbierto
     */
    public function test_scope_vigentes_curso_abierto()
    {
        // Vigente
        CursoAbierto::factory()->vigente()->create();
        
        // Próximo
        CursoAbierto::factory()->proximo()->create();
        
        // Finalizado
        CursoAbierto::factory()->finalizado()->create();

        $vigentes = CursoAbierto::vigentes()->get();

        $this->assertCount(1, $vigentes);
        foreach ($vigentes as $curso) {
            $ahora = Carbon::now();
            $this->assertLessThanOrEqual($ahora, $curso->fecha_inicio);
            $this->assertGreaterThanOrEqual($ahora, $curso->fecha_fin);
        }
    }

    /**
     * Test: Scope DelSemestre en CursoAbierto
     */
    public function test_scope_del_semestre_curso_abierto()
    {
        CursoAbierto::factory()->create(['semestre' => '2026-1']);
        CursoAbierto::factory()->create(['semestre' => '2026-2']);

        $del2026_1 = CursoAbierto::delSemestre('2026-1')->get();

        $this->assertCount(1, $del2026_1);
        $this->assertEquals('2026-1', $del2026_1[0]->semestre);
    }

    /**
     * Test: Scope DelDocente en CursoAbierto
     */
    public function test_scope_del_docente_curso_abierto()
    {
        $docente1 = fake()->uuid();
        $docente2 = fake()->uuid();

        CursoAbierto::factory()->count(2)->create(['docente_id' => $docente1]);
        CursoAbierto::factory()->create(['docente_id' => $docente2]);

        $del_docente1 = CursoAbierto::delDocente($docente1)->get();

        $this->assertCount(2, $del_docente1);
        foreach ($del_docente1 as $curso) {
            $this->assertEquals($docente1, $curso->docente_id);
        }
    }

    /**
     * Test: Scope Buscar en CursoAbierto
     */
    public function test_scope_buscar_curso_abierto()
    {
        CursoAbierto::factory()->create(['nombre_instancia' => 'Grupo A']);
        CursoAbierto::factory()->count(3)->create();

        $resultados = CursoAbierto::buscar('Grupo')->get();

        $this->assertGreaterThanOrEqual(1, count($resultados));
        foreach ($resultados as $curso) {
            $this->assertStringContainsString('Grupo', $curso->nombre_instancia);
        }
    }

    /**
     * Test: Scope Activas en Matricula
     */
    public function test_scope_activas_matricula()
    {
        Matricula::factory()->count(3)->activa()->create();
        Matricula::factory()->count(2)->completada()->create();

        $activas = Matricula::activas()->get();

        $this->assertCount(3, $activas);
        foreach ($activas as $matricula) {
            $this->assertEquals('activo', $matricula->estado);
        }
    }

    /**
     * Test: Scope PorEstado en Matricula
     */
    public function test_scope_por_estado_matricula()
    {
        Matricula::factory()->count(2)->activa()->create();
        Matricula::factory()->count(2)->retirada()->create();

        $retiradas = Matricula::porEstado('retirado')->get();

        $this->assertCount(2, $retiradas);
        foreach ($retiradas as $matricula) {
            $this->assertEquals('retirado', $matricula->estado);
        }
    }

    /**
     * Test: Scope Aprobadas en Nota
     */
    public function test_scope_aprobadas_nota()
    {
        Nota::factory()->count(3)->aprobada()->create();
        Nota::factory()->count(2)->reprobada()->create();

        $aprobadas = Nota::aprobadas()->get();

        $this->assertCount(3, $aprobadas);
        foreach ($aprobadas as $nota) {
            $this->assertGreaterThanOrEqual(3.0, $nota->calificacion);
        }
    }

    /**
     * Test: Scope Reprobadas en Nota
     */
    public function test_scope_reprobadas_nota()
    {
        Nota::factory()->count(2)->aprobada()->create();
        Nota::factory()->count(3)->reprobada()->create();

        $reprobadas = Nota::reprobadas()->get();

        $this->assertCount(3, $reprobadas);
        foreach ($reprobadas as $nota) {
            $this->assertLessThan(3.0, $nota->calificacion);
        }
    }
}
