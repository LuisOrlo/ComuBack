<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');
        $columns = collect(Schema::connection($connection)->getColumnListing('people.clientes_externos'));

        Schema::connection($connection)->table('people.clientes_externos', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('ruc')) {
                $table->string('ruc', 20)->nullable()->after('cedula');
            }
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('people.clientes_externos', function (Blueprint $table) {
            $table->dropColumn('ruc');
        });
    }
};
