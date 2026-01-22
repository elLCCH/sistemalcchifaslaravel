<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bibliotecaarchivoslcch', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('institucion_id');

            $table->string('categoria', 80)->nullable();
            $table->string('nombre_documento', 150)->nullable();
            $table->date('fecha')->nullable();
            $table->string('archivo', 300)->nullable();

            $table->string('estado', 15)->nullable();
            $table->string('visibilidad', 15)->nullable();

            $table->string('publicado_por', 120)->nullable();
            $table->string('dirigido', 120)->nullable();
            $table->text('descripcion')->nullable();

            $table->timestamps();

            $table->index(['institucion_id', 'categoria']);
            $table->index(['institucion_id', 'visibilidad']);

            $table->foreign('institucion_id')
                ->references('id')
                ->on('instituciones')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bibliotecaarchivoslcch');
    }
};
