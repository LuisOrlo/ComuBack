<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.default');
        $columns = collect(Schema::connection($connection)->getColumnListing('finance.transacciones_ingreso'));

        Schema::connection($connection)->table('finance.transacciones_ingreso', function (Blueprint $table) use ($columns) {
            if (!$columns->contains('grupo_pago_id')) {
                $table->uuid('grupo_pago_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.default'))->table('finance.transacciones_ingreso', function (Blueprint $table) {
            $table->dropIndex(['grupo_pago_id']);
            $table->dropColumn('grupo_pago_id');
        });
    }
};
