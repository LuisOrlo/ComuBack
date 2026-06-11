<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('estudiante_segmentos')->insert([
            [
                'id' => Str::uuid()->toString(),
                'nombre' => 'Deudores',
                'descripcion' => 'Estudiantes con pagos pendientes',
                'criterios' => json_encode(['estado_pago' => 'deudor']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'nombre' => 'Al dia',
                'descripcion' => 'Estudiantes con cuentas pagadas',
                'criterios' => json_encode(['estado_pago' => 'al_dia']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'nombre' => 'Alto Desempeno',
                'descripcion' => 'Estudiantes con promedio mayor a 8',
                'criterios' => json_encode(['promedio_min' => 8]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'nombre' => 'Veteranos',
                'descripcion' => 'Estudiantes con 3 o mas cursos',
                'criterios' => json_encode(['cursos_min' => 3]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('estudiante_segmentos')->whereIn('nombre', [
            'Deudores', 'Al dia', 'Alto Desempeno', 'Veteranos'
        ])->delete();
    }
};
