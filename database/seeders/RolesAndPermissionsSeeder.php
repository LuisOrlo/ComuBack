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
        $secretariaRole = Role::firstOrCreate(['name' => 'Secretaria']);

        // Define permissions
        $permissions = [
            'ver_estudiantes',
            'gestionar_estudiantes',
            'ver_cursos_propios',
            'gestionar_asistencia',
            'gestionar_notas',
            'ver_reportes_academicos',
            // Permisos de secretaria
            'ver_dashboard_secretaria',
            'ver_cuentas_cobrar',
            'registrar_pagos',
            'verificar_transacciones',
            'ver_cursos',
            'gestionar_matriculas',
            'ver_talleres',
            'gestionar_inscripciones_talleres',
            'ver_podcast',
            'gestionar_podcast',
            'ver_edicion_video',
            'gestionar_edicion_video',
            'registrar_asistencia',
            'gestionar_certificados',
            'entregar_certificados',
            'ver_equipos',
            'gestionar_alquileres',
            'gestionar_solicitudes_inscripcion',
            'ver_clientes_externos',
            'gestionar_personal',
            'gestionar_cuentas_sistema',
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

        $secretariaRole->givePermissionTo([
            'ver_dashboard_secretaria',
            'ver_estudiantes',
            'gestionar_estudiantes',
            'ver_cuentas_cobrar',
            'registrar_pagos',
            'verificar_transacciones',
            'ver_cursos',
            'gestionar_matriculas',
            'ver_talleres',
            'gestionar_inscripciones_talleres',
            'ver_podcast',
            'gestionar_podcast',
            'ver_edicion_video',
            'gestionar_edicion_video',
            'registrar_asistencia',
            'gestionar_certificados',
            'entregar_certificados',
            'ver_equipos',
            'gestionar_alquileres',
            'gestionar_solicitudes_inscripcion',
            'ver_clientes_externos',
        ]);

        // AUTO-ASIGNAR ROLES A CUENTAS EXISTENTES BASADO EN EL TIPO DE PERSONA
        // Protegido con hasRole para ser idempotente
        $cuentas = CuentaSistema::with('persona')->get();
        
        foreach ($cuentas as $cuenta) {
            if (!$cuenta->persona) continue;

            switch ($cuenta->persona->tipo) {
                case 'admin':
                    if (!$cuenta->hasRole($adminRole)) $cuenta->assignRole($adminRole);
                    break;
                case 'instructor':
                    if (!$cuenta->hasRole($instructorRole)) $cuenta->assignRole($instructorRole);
                    break;
                case 'staff':
                    if (!$cuenta->hasRole($staffRole)) $cuenta->assignRole($staffRole);
                    break;
                case 'secretaria':
                    if (!$cuenta->hasRole($secretariaRole)) $cuenta->assignRole($secretariaRole);
                    break;
            }
        }
    }
}
