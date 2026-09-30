<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');
        $columns = collect(Schema::connection($connection)->getColumnListing('academic.cursos_abiertos'));

        Schema::connection($connection)->table('academic.cursos_abiertos', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('precio_matricula')) {
                $table->decimal('precio_matricula', 10, 2)->default(0)->after('precio_base');
            }
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('academic.cursos_abiertos', function (Blueprint $table) {
            $table->dropColumn('precio_matricula');
        });
    }
};
