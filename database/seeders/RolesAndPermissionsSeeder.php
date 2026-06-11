<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\CuentaSistema;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create roles
        $adminRole = Role::firstOrCreate(['name' => 'Administrador']);
        $instructorRole = Role::firstOrCreate(['name' => 'Instructor']);
        $staffRole = Role::firstOrCreate(['name' => 'Staff']);

        // Define permissions
        $permissions = [
            'ver_estudiantes',
            'gestionar_estudiantes',
            'ver_cursos_propios',
            'gestionar_asistencia',
            'gestionar_notas',
            'ver_reportes_academicos',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Assign permissions to roles
        $adminRole->givePermissionTo(Permission::all());
        
        $instructorRole->givePermissionTo([
            'ver_cursos_propios',
            'gestionar_asistencia',
            'gestionar_notas',
        ]);

        $staffRole->givePermissionTo([
            'ver_estudiantes',
            'ver_reportes_academicos',
        ]);

        // AUTO-ASIGNAR ROLES A CUENTAS EXISTENTES BASADO EN EL TIPO DE PERSONA
        $cuentas = CuentaSistema::with('persona')->get();
        
        foreach ($cuentas as $cuenta) {
            if (!$cuenta->persona) continue;

            switch ($cuenta->persona->tipo) {
                case 'admin':
                    $cuenta->assignRole($adminRole);
                    break;
                case 'instructor':
                    $cuenta->assignRole($instructorRole);
                    break;
                case 'staff':
                case 'secretaria':
                    $cuenta->assignRole($staffRole);
                    break;
            }
        }
    }
}
