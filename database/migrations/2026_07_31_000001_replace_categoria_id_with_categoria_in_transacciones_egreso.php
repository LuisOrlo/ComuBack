<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('finance.transacciones_egreso', function ($table) {
            $table->dropForeign('transacciones_egreso_categoria_id_fkey');
            $table->dropColumn('categoria_id');
            if (!Schema::connection('pgsql')->hasColumn('finance.transacciones_egreso', 'categoria')) {
                $table->string('categoria', 100)->nullable();
            }
        });

        Schema::connection('pgsql')->dropIfExists('finance.categorias_egreso');
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('finance.transacciones_egreso', function ($table) {
            $table->integer('categoria_id')->nullable();
        });

        DB::connection('pgsql')->statement("
            CREATE TABLE IF NOT EXISTS finance.categorias_egreso (
                id integer NOT NULL,
                nombre character varying(100) NOT NULL,
                tipo_general character varying(50)
            )
        ");

        Schema::connection('pgsql')->table('finance.transacciones_egreso', function ($table) {
            $table->foreign('categoria_id')->references('id')->on('finance.categorias_egreso');
        });
    }
};
