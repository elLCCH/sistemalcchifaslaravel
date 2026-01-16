<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infoauditorias', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Quién hizo el movimiento
            $table->string('actor_type', 255)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_nombrecompleto', 120)->nullable();
            $table->string('actor_pertenencia', 50)->nullable();
            $table->string('actor_permisos', 100)->nullable();

            // Qué acción
            $table->string('accion', 20); // CREATE | UPDATE | DELETE | OTHER
            $table->string('recurso', 120)->nullable();
            $table->string('recurso_id', 64)->nullable();

            // Info de request
            $table->string('metodo', 10);
            $table->text('url');
            $table->string('route_name', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Datos (JSON)
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Resultado
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('mensaje', 255)->nullable();

            $table->timestamps();

            $table->index(['accion', 'created_at']);
            $table->index(['recurso', 'recurso_id']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infoauditorias');
    }
};
