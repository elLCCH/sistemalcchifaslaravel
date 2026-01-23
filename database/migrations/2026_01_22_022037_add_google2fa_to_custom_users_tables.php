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
        foreach (['usuarioslcchs', 'planteldocentes', 'planteladministrativos', 'estudiantesifas'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'google2fa_secret')) {
                    $table->text('google2fa_secret')->nullable()->after('Contrasenia');
                }
                if (!Schema::hasColumn($table->getTable(), 'google2fa_enabled')) {
                    $table->boolean('google2fa_enabled')->default(false)->after('google2fa_secret');
                }
                if (!Schema::hasColumn($table->getTable(), 'google2fa_confirmed_at')) {
                    $table->timestamp('google2fa_confirmed_at')->nullable()->after('google2fa_enabled');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['usuarioslcchs', 'planteldocentes', 'planteladministrativos', 'estudiantesifas'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                if (Schema::hasColumn($table->getTable(), 'google2fa_confirmed_at')) {
                    $table->dropColumn('google2fa_confirmed_at');
                }
                if (Schema::hasColumn($table->getTable(), 'google2fa_enabled')) {
                    $table->dropColumn('google2fa_enabled');
                }
                if (Schema::hasColumn($table->getTable(), 'google2fa_secret')) {
                    $table->dropColumn('google2fa_secret');
                }
            });
        }
    }
};
