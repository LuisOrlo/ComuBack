<?php

namespace Database\Seeders;

use App\Models\CuentaSistema;
use App\Models\Persona;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $persona = Persona::firstOrCreate(
            ['cedula' => env('ADMIN_CEDULA', '0000000001')],
            [
                'tipo' => 'admin',
                'nombres' => env('ADMIN_NOMBRES', 'Administrador'),
                'apellidos' => env('ADMIN_APELLIDOS', 'Sistema'),
                'correo' => env('ADMIN_EMAIL', 'admin@comunikate.com'),
                'celular' => env('ADMIN_CELULAR', '0000000000'),
                'es_activo' => true,
            ]
        );

        $cuenta = CuentaSistema::firstOrCreate(
            ['username' => env('ADMIN_USERNAME', 'admin')],
            [
                'persona_id' => $persona->id,
                'password_hash' => env('ADMIN_PASSWORD', 'admin123'),
            ]
        );

        if (!$cuenta->hasRole('Administrador')) {
            $cuenta->assignRole('Administrador');
        }
    }
}
