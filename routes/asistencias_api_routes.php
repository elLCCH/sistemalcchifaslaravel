<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AsistenciaSesionController;
use App\Http\Controllers\AsistenciaQrTokenController;
use App\Http\Controllers\AsistenciaScanController;
use App\Http\Controllers\PermisoAsistenciaController;
use App\Http\Controllers\LicenciasestudiantesifasController;
use App\Http\Middleware\CheckAbilities;

// ============================================================
// Asistencias - API
// Prefijo: /api/asistencias
// ============================================================

Route::prefix('asistencias')->middleware(['auth:sanctum'])->group(function () {

    // Abrir/crear sesión por materia (docente)
    Route::post('materias/{materiaId}/sesion', [AsistenciaSesionController::class, 'openByMateria']);

    // Listar sesiones por materia (docente - gestor de asistencias)
    Route::get('materias/{materiaId}/sesiones', [AsistenciaSesionController::class, 'listByMateria']);

    // Sesiones (docentes/admin)
    Route::get('sesiones', [AsistenciaSesionController::class, 'index']);
    Route::post('sesiones', [AsistenciaSesionController::class, 'store']);
    Route::get('sesiones/{id}', [AsistenciaSesionController::class, 'show']);
    Route::post('sesiones/{id}/cerrar', [AsistenciaSesionController::class, 'cerrar']);
    Route::post('sesiones/{id}/marcar', [AsistenciaSesionController::class, 'marcar']);
    Route::post('sesiones/{id}/quitar', [AsistenciaSesionController::class, 'quitarAsistencia']);
    Route::get('sesiones/{id}/registros', [AsistenciaSesionController::class, 'registros']);
    Route::get('sesiones/{id}/estudiantes', [AsistenciaSesionController::class, 'estudiantes']);
    Route::delete('sesiones/{id}', [AsistenciaSesionController::class, 'destroy']);

    // Mis asistencias (estudiante) por materia
    Route::get('mis-asistencias/materia/{materiaId}', [AsistenciaSesionController::class, 'misAsistenciasMateria']);

    // Materias del docente/admin con asistencia habilitada (para botón flotante)
    Route::get('mis-materias', [AsistenciaSesionController::class, 'misMaterias']);

    // QR rotativo
    Route::post('sesiones/{id}/qr', [AsistenciaQrTokenController::class, 'create']);
    Route::get('sesiones/{id}/qr/status', [AsistenciaQrTokenController::class, 'tokenStatus']);
    Route::post('sesiones/{id}/qr/stop', [AsistenciaQrTokenController::class, 'stop']);

    // Escaneo estudiante
    Route::post('scan', [AsistenciaScanController::class, 'scan']);

    // Permisos (secretaría)
    Route::get('permisos', [PermisoAsistenciaController::class, 'index'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::post('permisos', [PermisoAsistenciaController::class, 'store'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::delete('permisos/{id}', [PermisoAsistenciaController::class, 'destroy'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);

    // Licencias (nuevo) - secretaría
    Route::get('licencias', [LicenciasestudiantesifasController::class, 'index'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::get('licencias/buscar-estudiantes', [LicenciasestudiantesifasController::class, 'buscarEstudiantes'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::post('licencias', [LicenciasestudiantesifasController::class, 'store'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::post('licencias/{id}/aplicar', [LicenciasestudiantesifasController::class, 'aplicar'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::delete('licencias/{id}', [LicenciasestudiantesifasController::class, 'destroy'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
});
