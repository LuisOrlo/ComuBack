<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('pgsql')->create('academic.solicitudes_inscripcion', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            
            // Identificación del solicitante (estudiante con cuenta O participante externo)
            $table->uuid('persona_id')->nullable();
            $table->uuid('participante_externo_id')->nullable();
            $table->boolean('es_participante_externo')->default(false);
            
            // Curso solicitado
            $table->uuid('curso_abierto_id');
            
            // Información de pago
            $table->decimal('monto_solicitado', 10, 2);
            $table->string('tipo_pago', 20)->default('completo');
            $table->string('archivo_comprobante_url', 500)->nullable();
            $table->string('tipo_comprobante', 50)->nullable();
            $table->date('fecha_pago_declarada')->nullable();
            
            // Estado de validación
            $table->string('estado', 30)->default('registrado');
            
            // Validación staff
            $table->uuid('validado_por')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->text('observaciones_validacion')->nullable();
            $table->timestampTz('fecha_validacion')->nullable();
            
            // Timestamps y soft delete
            $table->timestampsTz();
            $table->softDeletesTz();
            
            // Foreign Keys
            $table->foreign('persona_id')
                ->references('id')
                ->on('people.personas')
                ->onDelete('cascade');
            
            $table->foreign('participante_externo_id')
                ->references('id')
                ->on('people.clientes_externos')
                ->onDelete('cascade');
            
            $table->foreign('curso_abierto_id')
                ->references('id')
                ->on('academic.cursos_abiertos')
                ->onDelete('cascade');
            
            $table->foreign('validado_por')
                ->references('id')
                ->on('people.personas')
                ->onDelete('set null');
        });
        
        // Check constraints (deben ejecutarse después del create)
        DB::statement("ALTER TABLE academic.solicitudes_inscripcion ADD CONSTRAINT check_excluyente_persona CHECK ((CASE WHEN persona_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN participante_externo_id IS NOT NULL THEN 1 ELSE 0 END) = 1)");
        DB::statement("ALTER TABLE academic.solicitudes_inscripcion ADD CONSTRAINT check_tipo_pago CHECK (tipo_pago IN ('completo', 'abono'))");
        DB::statement("ALTER TABLE academic.solicitudes_inscripcion ADD CONSTRAINT check_estado CHECK (estado IN ('registrado', 'pendiente_validacion', 'aprobado', 'rechazado', 'matricula_creada', 'cancelado'))");
        
        // Índices
        Schema::connection('pgsql')->table('academic.solicitudes_inscripcion', function (Blueprint $table) {
            $table->index('persona_id');
            $table->index('estado');
            $table->index('curso_abierto_id');
            $table->index('created_at');
            $table->index(['persona_id', 'estado']);
            $table->index(['curso_abierto_id', 'estado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('academic.solicitudes_inscripcion');
    }
};
