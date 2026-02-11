<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_lotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->unsignedInteger('instituciones_id')->nullable()->index();
            $table->unsignedInteger('anio_id')->nullable()->index();
            $table->string('resolucion', 100)->nullable();
            $table->string('nivel', 100)->nullable();
            $table->json('cursos_json')->nullable();

            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->timestamp('rolled_back_at')->nullable()->index();
            $table->string('rolled_back_by_type')->nullable();
            $table->unsignedBigInteger('rolled_back_by_id')->nullable();
        });

        Schema::create('asignaciones_lote_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('lote_uuid')->index();
            $table->unsignedInteger('infoestudiantesifas_id')->nullable()->index();

            $table->unsignedInteger('calificaciones_id')->nullable()->index();
            $table->unsignedInteger('materias_id')->nullable();

            $table->string('action', 20)->default('ASSIGN'); // ASSIGN | NOTE

            $table->text('prev_notas')->nullable();
            $table->string('prev_verificacion', 100)->nullable();
            $table->text('new_notas')->nullable();
            $table->string('new_verificacion', 100)->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_lote_items');
        Schema::dropIfExists('asignaciones_lotes');
    }
};
