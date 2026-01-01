<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AsistenciaSesionController;
use App\Http\Controllers\AsistenciaQrTokenController;
use App\Http\Controllers\AsistenciaScanController;
use App\Http\Controllers\PermisoAsistenciaController;
use App\Http\Middleware\CheckAbilities;

// ============================================================
// Asistencias - API
// Prefijo: /api/asistencias
// ============================================================

Route::prefix('asistencias')->middleware(['auth:sanctum'])->group(function () {

    // Sesiones (docentes/admin)
    Route::get('sesiones', [AsistenciaSesionController::class, 'index']);
    Route::post('sesiones', [AsistenciaSesionController::class, 'store']);
    Route::get('sesiones/{id}', [AsistenciaSesionController::class, 'show']);
    Route::post('sesiones/{id}/cerrar', [AsistenciaSesionController::class, 'cerrar']);
    Route::get('sesiones/{id}/registros', [AsistenciaSesionController::class, 'registros']);
    Route::get('sesiones/{id}/estudiantes', [AsistenciaSesionController::class, 'estudiantes']);

    // QR rotativo
    Route::post('sesiones/{id}/qr', [AsistenciaQrTokenController::class, 'create']);

    // Escaneo estudiante
    Route::post('scan', [AsistenciaScanController::class, 'scan']);

    // Permisos (secretaría)
    Route::get('permisos', [PermisoAsistenciaController::class, 'index'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::post('permisos', [PermisoAsistenciaController::class, 'store'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::delete('permisos/{id}', [PermisoAsistenciaController::class, 'destroy'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
});
