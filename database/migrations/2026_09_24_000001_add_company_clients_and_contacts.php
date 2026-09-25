<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('people.clientes_externos', function (Blueprint $table) {
            $table->string('tipo_cliente', 20)->default('persona')->after('id');
            $table->string('nombre_empresa', 150)->nullable()->after('nombres');
            $table->index('tipo_cliente', 'clientes_externos_tipo_cliente_idx');
        });

        DB::statement("ALTER TABLE people.clientes_externos ALTER COLUMN nombres DROP NOT NULL");
        DB::statement("UPDATE people.clientes_externos SET tipo_cliente = 'persona' WHERE tipo_cliente IS NULL");
        DB::statement("ALTER TABLE people.clientes_externos ADD CONSTRAINT clientes_externos_tipo_cliente_check CHECK (tipo_cliente IN ('persona', 'empresa'))");

        Schema::connection('pgsql')->create('people.clientes_externos_contactos', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('cliente_externo_id');
            $table->string('nombres', 100);
            $table->string('apellidos', 100)->nullable();
            $table->string('cargo', 100)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestampsTz();
            $table->index('cliente_externo_id', 'clientes_ext_contactos_cliente_idx');
            $table->foreign('cliente_externo_id', 'clientes_ext_contactos_cliente_fk')
                ->references('id')->on('people.clientes_externos')->cascadeOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX clientes_ext_contactos_principal_idx ON people.clientes_externos_contactos (cliente_externo_id) WHERE es_principal = true AND activo = true");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS people.clientes_ext_contactos_principal_idx');
        Schema::connection('pgsql')->dropIfExists('people.clientes_externos_contactos');
        DB::statement('ALTER TABLE people.clientes_externos DROP CONSTRAINT IF EXISTS clientes_externos_tipo_cliente_check');
        Schema::connection('pgsql')->table('people.clientes_externos', function (Blueprint $table) {
            $table->dropIndex('clientes_externos_tipo_cliente_idx');
            $table->dropColumn(['tipo_cliente', 'nombre_empresa']);
        });
        DB::statement("ALTER TABLE people.clientes_externos ALTER COLUMN nombres SET NOT NULL");
    }
};
