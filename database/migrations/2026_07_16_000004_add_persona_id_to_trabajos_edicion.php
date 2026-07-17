<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('services.trabajos_edicion', function (Blueprint $table) {
            $table->foreignUuid('persona_id')
                ->nullable()
                ->constrained('people.personas')
                ->nullOnDelete();

            $table->index('persona_id', 'services_trabajos_edicion_persona_id_index');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('services.trabajos_edicion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
        });
    }
};
