<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capture_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token', 128)->unique();
            $table->unsignedBigInteger('institucion_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('estudianteifas_id')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['institucion_id', 'status']);
            $table->index(['estudianteifas_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capture_sessions');
    }
};
