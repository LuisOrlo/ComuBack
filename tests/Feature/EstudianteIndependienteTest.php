<?php

namespace Tests\Feature;

use App\Models\PerfilEstudiante;
use App\Models\Persona;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class EstudianteIndependienteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_crea_un_estudiante_minimo_sin_matricula_ni_finanzas(): void
    {
        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
        ]);

        $response->assertCreated();
        $persona = Persona::where('nombres', 'Ana')->where('apellidos', 'Pérez')->firstOrFail();

        $this->assertSame('estudiante', $persona->tipo);
        $this->assertDatabaseHas('people.perfil_estudiante', ['persona_id' => $persona->id]);
        $this->assertDatabaseCount('academic.matriculas', 0);
        $this->assertDatabaseCount('academic.solicitudes_inscripcion', 0);
        $this->assertDatabaseCount('finance.cuentas_por_cobrar', 0);
        $this->assertDatabaseCount('finance.lineas_pago_modulo', 0);
        $this->assertDatabaseCount('finance.transacciones_ingreso', 0);
    }

    public function test_persiste_los_campos_opcionales_permitidos(): void
    {
        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'Luis',
            'apellidos' => 'Gómez',
            'cedula' => '0102030405',
            'correo' => 'luis@example.com',
            'celular' => '0999999999',
            'notas_internas' => 'Prefiere comunicación por correo.',
            'ocupacion' => 'Diseñador',
            'direccion' => 'Av. Principal',
            'estado_civil' => 'soltero',
            'edad' => 29,
            'nivel_educativo' => 'superior',
        ]);

        $response->assertCreated();
        $persona = Persona::where('cedula', '0102030405')->firstOrFail();
        $perfil = $persona->perfilEstudiante;

        $this->assertNotNull($perfil);
        $this->assertSame('luis@example.com', $persona->correo);
        $this->assertSame('0999999999', $persona->celular);
        $this->assertSame('Prefiere comunicación por correo.', $perfil->notas_internas);
        $this->assertSame('Diseñador', $perfil->ocupacion);
        $this->assertSame(29, $perfil->edad);
        $this->assertSame('superior', $perfil->nivel_educativo);
    }

    public function test_rechaza_duplicado_si_ya_existe_estudiante_con_perfil(): void
    {
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'cedula' => '1712345678',
            'nombres' => 'Persona',
            'apellidos' => 'Existente',
        ]);
        PerfilEstudiante::create(['persona_id' => $persona->id]);

        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'Otro',
            'apellidos' => 'Registro',
            'cedula' => '1712345678',
        ]);

        $response->assertConflict()
            ->assertJsonPath('mensaje', 'Ya existe un estudiante registrado con esta cédula.')
            ->assertJsonPath('estudiante_id', $persona->id);
        $this->assertSame(1, Persona::where('cedula', '1712345678')->count());
    }

    public function test_reutiliza_persona_estudiante_sin_perfil(): void
    {
        $persona = Persona::create([
            'tipo' => 'estudiante',
            'cedula' => '1712345679',
            'nombres' => 'Importado',
            'apellidos' => 'Sin Perfil',
        ]);

        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'No reemplazar',
            'apellidos' => 'Nombre',
            'cedula' => '1712345679',
            'ocupacion' => 'Docente',
        ]);

        $response->assertOk();
        $this->assertSame(1, Persona::where('cedula', '1712345679')->count());
        $this->assertDatabaseHas('people.perfil_estudiante', [
            'persona_id' => $persona->id,
            'ocupacion' => 'Docente',
        ]);
        $this->assertDatabaseHas('people.personas', [
            'id' => $persona->id,
            'nombres' => 'Importado',
            'tipo' => 'estudiante',
        ]);
    }

    public function test_no_modifica_una_persona_de_otro_rol_con_la_misma_cedula(): void
    {
        $persona = Persona::create([
            'tipo' => 'instructor',
            'cedula' => '1712345680',
            'nombres' => 'Instructor',
            'apellidos' => 'Existente',
        ]);

        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'Intento',
            'apellidos' => 'Estudiante',
            'cedula' => '1712345680',
        ]);

        $response->assertConflict();
        $this->assertDatabaseHas('people.personas', [
            'id' => $persona->id,
            'tipo' => 'instructor',
        ]);
        $this->assertDatabaseCount('people.perfil_estudiante', 0);
    }

    public function test_hace_rollback_si_no_puede_crear_el_perfil(): void
    {
        PerfilEstudiante::creating(function (): void {
            throw new RuntimeException('Fallo controlado al crear perfil');
        });

        $this->withoutExceptionHandling();

        try {
            $this->authenticatedPost('/api/personas/estudiantes', [
                'nombres' => 'Rollback',
                'apellidos' => 'Controlado',
                'cedula' => '1712345681',
            ]);
            $this->fail('Se esperaba la excepción controlada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo controlado al crear perfil', $exception->getMessage());
        } finally {
            PerfilEstudiante::flushEventListeners();
        }

        $this->assertDatabaseMissing('people.personas', ['cedula' => '1712345681']);
        $this->assertDatabaseCount('people.perfil_estudiante', 0);
    }

    public function test_estudiante_sin_matricula_se_recupera_con_estado_vacio(): void
    {
        $response = $this->authenticatedPost('/api/personas/estudiantes', [
            'nombres' => 'Perfil',
            'apellidos' => 'Vacío',
        ]);

        $id = $response->json('datos.id');

        $this->authenticatedGet("/api/personas/estudiantes/{$id}")
            ->assertOk()
            ->assertJsonPath('datos.total_cursos', 0)
            ->assertJsonPath('datos.estado_pago', 'ninguno')
            ->assertJsonPath('datos.saldo_pendiente', 0);

        $this->authenticatedGet("/api/personas/estudiantes/{$id}/academic-profile")
            ->assertOk()
            ->assertJsonPath('datos.matriculas', []);
    }
}
