<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClienteExternoTest extends TestCase
{
    public function test_empresa_se_crea_con_contactos_y_se_busca_por_nombre(): void
    {
        $this->createAuthenticatedUser();

        $response = $this->postJson('/api/academic/servicios/clientes-externos', [
            'tipo_cliente' => 'empresa',
            'nombre_empresa' => 'Comunicaciones Andinas',
            'celular' => '0990000000',
            'correo' => 'empresa-' . uniqid() . '@example.test',
            'contactos' => [
                [
                    'nombres' => 'Maria',
                    'apellidos' => 'Lopez',
                    'cargo' => 'Administracion',
                    'es_principal' => true,
                    'activo' => true,
                ],
                [
                    'nombres' => 'Juan',
                    'apellidos' => 'Perez',
                    'cargo' => 'Produccion',
                    'es_principal' => false,
                    'activo' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tipo_cliente', 'empresa')
            ->assertJsonPath('data.nombre_empresa', 'Comunicaciones Andinas')
            ->assertJsonCount(2, 'data.contactos');

        $this->getJson('/api/academic/servicios/clientes-externos?search=Andinas')
            ->assertOk()
            ->assertJsonPath('data.0.tipo_cliente', 'empresa');
    }

    public function test_persona_existente_sigue_siendo_persona(): void
    {
        $this->createAuthenticatedUser();

        $response = $this->postJson('/api/academic/servicios/clientes-externos', [
            'tipo_cliente' => 'persona',
            'nombres' => 'Carlos',
            'apellidos' => 'Perez',
            'cedula' => '1799999999',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tipo_cliente', 'persona')
            ->assertJsonPath('data.nombres', 'Carlos');
    }
}
