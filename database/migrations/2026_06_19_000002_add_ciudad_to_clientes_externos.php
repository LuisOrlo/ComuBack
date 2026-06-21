<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('database.default'))->table('people.clientes_externos', function (Blueprint $table) {
            $table->string('ciudad', 100)->nullable()->after('celular');
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('people.clientes_externos', function (Blueprint $table) {
            $table->dropColumn('ciudad');
        });
    }
};
