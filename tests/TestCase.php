<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Crear un usuario autenticado para tests
     */
    protected function createAuthenticatedUser()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    /**
     * Obtener un token de autenticación
     */
    protected function getAuthToken()
    {
        $user = \App\Models\User::factory()->create();
        return $user->createToken('test-token')->plainTextToken;
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
