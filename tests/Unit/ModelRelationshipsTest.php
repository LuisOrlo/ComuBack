<?php

namespace Tests\Unit;

use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Horario;
use App\Models\Modulo;
use App\Models\Matricula;
use App\Models\Nota;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: CatalogoCurso tiene muchos CursoAbierto
     */
    public function test_catalogo_has_many_cursos_abiertos()
    {
        $catalogo = CatalogoCurso::factory()->create();
        $cursos = CursoAbierto::factory()->count(3)->create(['catalogo_curso_id' => $catalogo->id]);

        $this->assertCount(3, $catalogo->cursosAbiertos);
        $this->assertEquals($cursos[0]->id, $catalogo->cursosAbiertos[0]->id);
    }

    /**
     * Test: CatalogoCurso tiene muchos Modulo (predeterminados)
     */
    public function test_catalogo_has_many_modulos()
    {
        $catalogo = CatalogoCurso::factory()->create();
        $modulos = Modulo::factory()->count(2)->delCatalogo()->create(['catalogo_curso_id' => $catalogo->id]);

        $this->assertCount(2, $catalogo->modulosPredeterminados);
    }

    /**
     * Test: CursoAbierto belongs to CatalogoCurso
     */
    public function test_curso_abierto_belongs_to_catalogo()
    {
        $catalogo = CatalogoCurso::factory()->create();
        $curso = CursoAbierto::factory()->create(['catalogo_curso_id' => $catalogo->id]);

        $this->assertEquals($catalogo->id, $curso->catalogo->id);
    }

    /**
     * Test: CursoAbierto has many Horario
     */
    public function test_curso_abierto_has_many_horarios()
    {
        $curso = CursoAbierto::factory()->create();
        $horarios = Horario::factory()->count(4)->create(['curso_abierto_id' => $curso->id]);

        $this->assertCount(4, $curso->horarios);
    }

    /**
     * Test: CursoAbierto has many Modulo
     */
    public function test_curso_abierto_has_many_modulos()
    {
        $curso = CursoAbierto::factory()->create();
        $modulos = Modulo::factory()->count(2)->delCurso()->create(['curso_abierto_id' => $curso->id]);

        $this->assertCount(2, $curso->modulos);
    }

    /**
     * Test: CursoAbierto has many Matricula
     */
    public function test_curso_abierto_has_many_matriculas()
    {
        $curso = CursoAbierto::factory()->create();
        $matriculas = Matricula::factory()->count(5)->create(['curso_abierto_id' => $curso->id]);

        $this->assertCount(5, $curso->matriculas);
    }

    /**
     * Test: Matricula belongs to CursoAbierto
     */
    public function test_matricula_belongs_to_curso_abierto()
    {
        $curso = CursoAbierto::factory()->create();
        $matricula = Matricula::factory()->create(['curso_abierto_id' => $curso->id]);

        $this->assertEquals($curso->id, $matricula->cursoAbierto->id);
    }

    /**
     * Test: Matricula has many Nota
     */
    public function test_matricula_has_many_notas()
    {
        $matricula = Matricula::factory()->create();
        $notas = Nota::factory()->count(3)->create(['matricula_id' => $matricula->id]);

        $this->assertCount(3, $matricula->notas);
    }

    /**
     * Test: Modulo has many Nota
     */
    public function test_modulo_has_many_notas()
    {
        $modulo = Modulo::factory()->create();
        $notas = Nota::factory()->count(5)->create(['modulo_id' => $modulo->id]);

        $this->assertCount(5, $modulo->notas);
    }

    /**
     * Test: Nota belongs to Matricula
     */
    public function test_nota_belongs_to_matricula()
    {
        $matricula = Matricula::factory()->create();
        $nota = Nota::factory()->create(['matricula_id' => $matricula->id]);

        $this->assertEquals($matricula->id, $nota->matricula->id);
    }

    /**
     * Test: Nota belongs to Modulo
     */
    public function test_nota_belongs_to_modulo()
    {
        $modulo = Modulo::factory()->create();
        $nota = Nota::factory()->create(['modulo_id' => $modulo->id]);

        $this->assertEquals($modulo->id, $nota->modulo->id);
    }
}
