<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración de endpoints externos (p.ej. Ministerio)
        Schema::create('api_integration_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instituciones_id')->nullable();
            $table->string('nombre', 100);              // ej: "Ministerio de Educación"
            $table->string('slug', 60)->unique();        // ej: "ministerio-educacion"
            $table->string('base_url', 500)->nullable(); // URL del endpoint externo
            $table->string('auth_type', 30)->default('bearer_token'); // bearer_token | api_key | oauth2
            $table->text('auth_credentials')->nullable(); // JSON cifrado: {token, client_id, client_secret, ...}
            $table->json('headers')->nullable();          // Headers adicionales
            $table->boolean('activo')->default(false);    // Activar/desactivar integración
            $table->boolean('auto_sync')->default(false); // Sincronización automática diaria
            $table->string('webhook_secret', 255)->nullable(); // Secreto para validar webhooks entrantes
            $table->timestamps();
        });

        // Log de intentos de integración (envíos y recepciones)
        Schema::create('api_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('config_id');
            $table->string('tipo', 30);            // ENVIO | RECEPCION | WEBHOOK
            $table->string('endpoint', 500);
            $table->string('metodo', 10);           // GET | POST | PUT | DELETE
            $table->json('payload_enviado')->nullable();
            $table->integer('status_code')->nullable();
            $table->text('respuesta')->nullable();
            $table->boolean('exitoso')->default(false);
            $table->string('error_mensaje', 1000)->nullable();
            $table->string('iniciado_por', 100)->nullable(); // "artisan:sync-daily", "manual:admin@1", etc
            $table->integer('registros_enviados')->default(0);
            $table->timestamps();

            $table->foreign('config_id')->references('id')->on('api_integration_configs')->onDelete('cascade');
            $table->index(['config_id', 'created_at']);
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_integration_logs');
        Schema::dropIfExists('api_integration_configs');
    }
};
