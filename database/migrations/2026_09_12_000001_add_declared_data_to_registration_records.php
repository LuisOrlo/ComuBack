<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic.solicitudes_inscripcion', function (Blueprint $table): void {
            $table->json('datos_declarados')->nullable()->after('observaciones_validacion');
        });

        Schema::table('academic.inscripciones_taller', function (Blueprint $table): void {
            $table->json('datos_declarados')->nullable()->after('nivel_educativo');
        });

        $permission = Permission::firstOrCreate(['name' => 'gestionar_personal', 'guard_name' => 'web']);
        $adminRole = Role::where('name', 'Administrador')->where('guard_name', 'web')->first();
        $adminRole?->givePermissionTo($permission);
    }

    public function down(): void
    {
        Schema::table('academic.inscripciones_taller', function (Blueprint $table): void {
            $table->dropColumn('datos_declarados');
        });

        Schema::table('academic.solicitudes_inscripcion', function (Blueprint $table): void {
            $table->dropColumn('datos_declarados');
        });
    }
};
