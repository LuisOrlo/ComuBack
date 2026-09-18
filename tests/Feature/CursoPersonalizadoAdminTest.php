<?php

namespace Tests\Feature;

use App\Models\CuentaSistema;
use App\Models\CursoAbierto;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Persona;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CursoPersonalizadoAdminTest extends TestCase
{
    private function autenticar(): void
    {
        $persona = Persona::create([
            'tipo' => 'staff',
            'cedula' => (string) random_int(1000000000, 1999999999),
            'nombres' => 'Administrador',
            'apellidos' => 'Prueba',
            'correo' => Str::uuid() . '@example.test',
            'es_activo' => true,
        ]);

        $cuenta = CuentaSistema::create([
            'persona_id' => $persona->id,
            'username' => Str::uuid()->toString(),
            'password_hash' => Hash::make('secret'),
        ]);
        $cuenta->assignRole(Role::findOrCreate('Administrador', 'web'));
        $this->actingAs($cuenta, 'sanctum');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Curso personalizado administrativo',
            'descripcion' => null,
            'docente_id' => null,
            'modalidad' => 'virtual',
            'ciudad_id' => null,
            'fecha_inicio' => now()->addDays(3)->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fin' => '20:00',
            'precio_total' => 300,
            'capacidad' => 10,
        ], $overrides);
    }

    private function crearCurso(array $overrides = []): CursoAbierto
    {
        return CursoAbierto::create(array_merge([
            'catalogo_curso_id' => null,
            'es_personalizado' => true,
            'nombre_instancia' => 'Personalizado existente',
            'modalidad' => 'virtual',
            'fecha_inicio' => now()->addDays(3)->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
            'precio_base' => 300,
            'capacidad_maxima' => 10,
            'es_activo' => true,
        ], $overrides));
    }

    public function test_lista_solo_cursos_personalizados(): void
    {
        $this->autenticar();
        $this->crearCurso();
        CursoAbierto::create([
            'catalogo_curso_id' => null,
            'es_personalizado' => false,
            'nombre_instancia' => 'Curso normal',
            'modalidad' => 'virtual',
            'fecha_inicio' => now()->addDays(3),
            'fecha_fin' => now()->addDays(4),
            'precio_base' => 100,
            'capacidad_maxima' => 10,
            'es_activo' => true,
        ]);

        $response = $this->getJson('/api/academic/cursos-personalizados');
        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.es_personalizado', true);
    }

    public function test_no_permite_detalle_edicion_o_eliminacion_de_curso_normal(): void
    {
        $this->autenticar();
        $curso = $this->crearCurso(['es_personalizado' => false]);

        $this->getJson('/api/academic/cursos-personalizados/' . $curso->id)->assertNotFound();
        $this->putJson('/api/academic/cursos-personalizados/' . $curso->id, $this->payload())->assertNotFound();
        $this->deleteJson('/api/academic/cursos-personalizados/' . $curso->id)->assertNotFound();
    }

    public function test_crea_personalizado_sin_aceptar_discriminador_del_cliente(): void
    {
        $this->autenticar();
        $response = $this->postJson('/api/academic/cursos-personalizados', $this->payload(['es_personalizado' => false]));

        $response->assertCreated();
        $this->assertDatabaseHas('academic.cursos_abiertos', [
            'id' => $response->json('data.id'),
            'es_personalizado' => true,
            'catalogo_curso_id' => null,
        ]);
    }

    public function test_valida_ciudad_modalidad_y_fecha_de_un_dia(): void
    {
        $this->autenticar();
        $this->postJson('/api/academic/cursos-personalizados', $this->payload(['modalidad' => 'presencial']))->assertStatus(422);
        $response = $this->postJson('/api/academic/cursos-personalizados', $this->payload(['modalidad' => 'virtual', 'ciudad_id' => 999999]));
        $response->assertStatus(422);
        $this->postJson('/api/academic/cursos-personalizados', $this->payload(['fecha_fin' => now()->addDays(2)->toDateString()]))->assertCreated();
    }

    public function test_rechaza_reduccion_de_capacidad_por_debajo_de_matriculados(): void
    {
        $this->autenticar();
        $curso = $this->crearCurso(['capacidad_maxima' => 10]);
        for ($i = 0; $i < 6; $i++) {
            $persona = Persona::create(['tipo' => 'estudiante', 'cedula' => (string) random_int(1000000000, 1999999999), 'nombres' => 'Estudiante', 'apellidos' => (string) $i, 'correo' => Str::uuid() . '@example.test', 'es_activo' => true]);
            Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $curso->id, 'estado' => Matricula::ESTADO_ACTIVO]);
        }

        $this->putJson('/api/academic/cursos-personalizados/' . $curso->id, $this->payload(['capacidad' => 5]))->assertStatus(422);
        $this->putJson('/api/academic/cursos-personalizados/' . $curso->id, $this->payload(['capacidad' => 6]))->assertOk();
        $this->putJson('/api/academic/cursos-personalizados/' . $curso->id, $this->payload(['capacidad' => 8]))->assertOk();
    }

    public function test_eliminacion_solo_se_bloquea_con_matriculas(): void
    {
        $this->autenticar();
        $sinMatricula = $this->crearCurso();
        $this->deleteJson('/api/academic/cursos-personalizados/' . $sinMatricula->id)->assertOk();

        $conMatricula = $this->crearCurso();
        $persona = Persona::create(['tipo' => 'estudiante', 'cedula' => (string) random_int(1000000000, 1999999999), 'nombres' => 'Estudiante', 'apellidos' => 'Matriculado', 'correo' => Str::uuid() . '@example.test', 'es_activo' => true]);
        Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $conMatricula->id, 'estado' => Matricula::ESTADO_ACTIVO]);
        $this->deleteJson('/api/academic/cursos-personalizados/' . $conMatricula->id)->assertStatus(422);
    }

    public function test_detalle_expone_estudiantes_y_resumen_financiero(): void
    {
        $this->autenticar();
        $curso = $this->crearCurso();
        $response = $this->getJson('/api/academic/cursos-personalizados/' . $curso->id);
        $response->assertOk()->assertJsonStructure(['data', 'estudiantes', 'finanzas']);
        $response->assertJsonPath('finanzas.total_esperado', 0);
    }

    public function test_resumen_financiero_con_pago_completo(): void
    {
        $this->autenticar();
        $curso = $this->crearCurso();
        $persona = Persona::create(['tipo' => 'estudiante', 'cedula' => (string) random_int(1000000000, 1999999999), 'nombres' => 'Estudiante', 'apellidos' => 'Pagado', 'correo' => Str::uuid() . '@example.test', 'es_activo' => true]);
        $matricula = Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $curso->id, 'estado' => Matricula::ESTADO_ACTIVO]);
        CuentaPorCobrar::create(['matricula_id' => $matricula->id, 'monto_total' => 300, 'monto_abonado' => 300, 'estado' => CuentaPorCobrar::ESTADO_PAGADO]);

        $this->getJson('/api/academic/cursos-personalizados/' . $curso->id)
            ->assertOk()
            ->assertJsonPath('finanzas.total_esperado', 300)
            ->assertJsonPath('finanzas.total_abonado', 300)
            ->assertJsonPath('finanzas.saldo_pendiente', 0)
            ->assertJsonPath('finanzas.cuentas_pagadas', 1);
    }

    public function test_resumen_financiero_con_abono(): void
    {
        $this->autenticar();
        $curso = $this->crearCurso();
        $persona = Persona::create(['tipo' => 'estudiante', 'cedula' => (string) random_int(1000000000, 1999999999), 'nombres' => 'Estudiante', 'apellidos' => 'Abonado', 'correo' => Str::uuid() . '@example.test', 'es_activo' => true]);
        $matricula = Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $curso->id, 'estado' => Matricula::ESTADO_ACTIVO]);
        CuentaPorCobrar::create(['matricula_id' => $matricula->id, 'monto_total' => 300, 'monto_abonado' => 100, 'estado' => CuentaPorCobrar::ESTADO_ABONADO]);

        $this->getJson('/api/academic/cursos-personalizados/' . $curso->id)
            ->assertOk()
            ->assertJsonPath('finanzas.total_esperado', 300)
            ->assertJsonPath('finanzas.total_abonado', 100)
            ->assertJsonPath('finanzas.saldo_pendiente', 200)
            ->assertJsonPath('finanzas.cuentas_abonadas', 1);
    }
}
