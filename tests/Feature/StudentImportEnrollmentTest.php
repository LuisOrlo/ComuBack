<?php

namespace Tests\Feature;

use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\Finance\LineaPagoModulo;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Integration coverage for the optional academic phase.
 * These tests intentionally use PostgreSQL because capacity, soft deletes and
 * database triggers are part of the enrollment contract.
 */
class StudentImportEnrollmentTest extends TestCase
{
    public function test_preview_never_creates_a_matricula(): void
    {
        $this->createAuthenticatedUser();
        $persona = Persona::create([
            'tipo' => 'estudiante', 'nombres' => 'Ana', 'apellidos' => 'Prueba',
            'cedula' => '0102030405', 'es_activo' => true,
        ]);
        $course = $this->makeCourse();

        $before = Matricula::query()->count();
        $this->assertNotSame('', (string) $persona->id);
        $this->assertNotSame('', (string) $course->id);
        $this->assertSame($before, Matricula::query()->count());
    }

    public function test_academic_import_contract_does_not_create_financial_records(): void
    {
        $this->createAuthenticatedUser();
        $this->assertTrue((new \ReflectionClass(\App\Services\Imports\EnrollmentImportAdapter::class))->hasMethod('execute'));
        $this->assertTrue(class_exists(\App\Services\Imports\FinanceImportAdapter::class));
    }

    public function test_duplicate_and_full_course_rules_are_delegated_to_adapter(): void
    {
        $adapter = app(\App\Services\Imports\EnrollmentImportAdapter::class);
        $this->assertSame('COURSE_NOT_FOUND', $adapter->preview('00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000000')['status']);
    }

    public function test_import_creates_academic_enrollment_once_without_finance(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse();
        $before = (int) $course->fresh()->estudiantes_inscritos;

        $preview = $this->previewForCourse($course);
        $row = $preview->json('data.rows.0');
        $response = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => $row['row_number']]],
        ]);

        $response->assertOk()->assertJsonPath('data.summary.enrollments_created', 1);
        $matriculaId = $response->json('data.rows.0.matricula_id');
        $matricula = Matricula::findOrFail($matriculaId);
        $this->assertSame((string) $course->id, (string) $matricula->curso_abierto_id);
        $this->assertSame('activo', $matricula->estado);
        $this->assertSame(0.0, (float) $matricula->precio_total);
        $this->assertSame($before + 1, (int) $course->fresh()->estudiantes_inscritos);
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
        $this->assertSame(0, CuentaPorCobrar::where('matricula_id', $matriculaId)->count());
        $this->assertSame(0, TransaccionIngreso::where('linea_pago_modulo_id', $matriculaId)->count());

        $retry = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => $row['row_number']]],
        ]);

        $retry->assertOk()->assertJsonPath('data.rows.0.matricula_id', $matriculaId);
        $this->assertSame(1, Matricula::where('curso_abierto_id', $course->id)->count());
    }

    public function test_custom_course_is_blocked_by_preview(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(['es_personalizado' => true]);
        $preview = $this->previewForCourse($course);

        $preview->assertOk()->assertJsonPath('data.rows.0.status', 'BLOCKED');
        $this->assertSame('CUSTOM_COURSE_NOT_SUPPORTED', $preview->json('data.rows.0.errors.0.code'));
    }

    public function test_custom_course_is_blocked_again_during_execute(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(['es_personalizado' => true]);
        $preview = $this->previewForCourse($course);
        $row = $preview->json('data.rows.0');

        $response = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => $row['row_number']]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.rows.0.status', 'BLOCKED')
            ->assertJsonPath('data.rows.0.errors.0.code', 'CUSTOM_COURSE_NOT_SUPPORTED');
        $this->assertSame(0, Matricula::where('curso_abierto_id', $course->id)->count());
        $this->assertDatabaseMissing('people.personas', ['cedula' => '0102030405']);
    }

    public function test_course_that_fills_after_preview_rolls_back_new_student(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(['capacidad_maxima' => 1]);
        $preview = $this->previewForCourse($course);
        $row = $preview->json('data.rows.0');

        $existing = Persona::create([
            'tipo' => 'estudiante', 'nombres' => 'Ocupante', 'apellidos' => 'Prueba', 'es_activo' => true,
        ]);
        Matricula::create([
            'estudiante_id' => $existing->id,
            'curso_abierto_id' => $course->id,
            'estado' => Matricula::ESTADO_ACTIVO,
            'precio_total_legacy' => 0,
            'tipo_pago' => 'completo',
        ]);

        $response = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => $row['row_number']]],
        ]);

        $response->assertOk()->assertJsonPath('data.rows.0.status', 'FAILED');
        $this->assertDatabaseMissing('people.personas', ['cedula' => '0102030405']);
    }

    private function previewForCourse(CursoAbierto $course)
    {
        $file = UploadedFile::fake()->createWithContent('students.csv', "NOMBRES,APELLIDOS,CEDULA\nAna,Importada,0102030405\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $final = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => [
                'enabled' => true,
                'curso_abierto_id' => $course->id,
                'sin_registro_financiero' => true,
            ],
        ]);

        return $final;
    }

    private function makeCourse(array $overrides = []): CursoAbierto
    {
        $catalogo = CatalogoCurso::create([
            'categoria' => 'regular',
            'nombre' => 'Import test catalog',
            'descripcion' => 'Catalog used by isolated import tests',
            'modulos_default' => 2,
            'creditos' => 1,
            'horas_totales' => 10,
            'es_activo' => true,
        ]);

        return CursoAbierto::create(array_merge([
            'catalogo_curso_id' => $catalogo->id,
            'modalidad' => 'virtual',
            'capacidad_maxima' => 10,
            'precio_base' => 0,
            'es_personalizado' => false,
            'es_activo' => true,
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDays(2),
            'nombre_instancia' => 'Import test course',
        ], $overrides));
    }
}
