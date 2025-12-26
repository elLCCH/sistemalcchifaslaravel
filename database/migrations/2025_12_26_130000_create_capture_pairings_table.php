<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capture_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('token', 128)->unique();
            $table->unsignedBigInteger('institucion_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('device_label', 100)->nullable();
            $table->string('pending_capture_token', 128)->nullable();
            $table->dateTime('linked_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['institucion_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_pairings');
    }
};
