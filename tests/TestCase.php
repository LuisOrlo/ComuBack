<?php

namespace Tests;

use App\Models\CuentaSistema;
use App\Models\Persona;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /**
     * Crear un usuario autenticado para tests
     */
    protected function createAuthenticatedUser()
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
        $role = Role::findOrCreate('Administrador', 'web');
        $cuenta->assignRole($role);
        $this->actingAs($cuenta, 'sanctum');

        return $cuenta;
    }

    /**
     * Obtener un token de autenticación
     */
    protected function getAuthToken()
    {
        return $this->createAuthenticatedUser()->createToken('test-token')->plainTextToken;
    }

    /**
     * Helper para hacer requests autenticadas
     */
    protected function authenticatedGet($uri, $headers = [])
    {
        $token = $this->getAuthToken();
        return $this->getJson($uri, array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers));
    }

    protected function authenticatedPost($uri, $data = [], $headers = [])
    {
        $token = $this->getAuthToken();
        return $this->postJson($uri, $data, array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers));
    }

    protected function authenticatedPut($uri, $data = [], $headers = [])
    {
        $token = $this->getAuthToken();
        return $this->putJson($uri, $data, array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers));
    }

    protected function authenticatedDelete($uri, $headers = [])
    {
        $token = $this->getAuthToken();
        return $this->deleteJson($uri, [], array_merge([
            'Authorization' => "Bearer {$token}",
        ], $headers));
    }
}
