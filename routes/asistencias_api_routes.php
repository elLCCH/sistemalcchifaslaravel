<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AsistenciaSesionController;
use App\Http\Controllers\AsistenciaQrTokenController;
use App\Http\Controllers\AsistenciaScanController;
use App\Http\Controllers\PermisoAsistenciaController;
use App\Http\Controllers\LicenciasestudiantesifasController;
use App\Http\Middleware\CheckAbilities;
use App\Http\Middleware\UpdateTokenExpiration;

// ============================================================
// Asistencias - API
// Prefijo: /api/asistencias
// ============================================================

Route::prefix('asistencias')->middleware(['auth:sanctum', UpdateTokenExpiration::class])->group(function () {

    // Abrir/crear sesión por materia (docente)
    Route::post('materias/{materiaId}/sesion', [AsistenciaSesionController::class, 'openByMateria']);

    // Listar sesiones por materia (docente - gestor de asistencias)
    Route::get('materias/{materiaId}/sesiones', [AsistenciaSesionController::class, 'listByMateria']);

    // Reporte de asistencias por materia (docente/admin) - rango de fechas
    Route::get('materias/{materiaId}/reporte', [AsistenciaSesionController::class, 'reporteAsistenciasMateria']);

    // Importación grupal de asistencias (modo REGISTRO DE IMPORTACIÓN VIRTUAL)
    Route::post('materias/{materiaId}/sesiones-grupo', [AsistenciaSesionController::class, 'crearSesionesGrupo']);
    Route::post('materias/{materiaId}/marcar-grupo', [AsistenciaSesionController::class, 'marcarGrupo']);

    // Materias asignadas de un estudiante (para selector de reporte de asistencias)
    Route::get('estudiante/{infoId}/materias', [AsistenciaSesionController::class, 'materiasEstudiante']);

    // Reporte de asistencias de UN estudiante en todas sus materias (docente/admin)
    Route::get('estudiante/{infoId}/reporte-asistencias', [AsistenciaSesionController::class, 'reporteAsistenciasEstudiante']);

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

    // UX: mostrar/ocultar botones de Scan (estudiante) según materias del año predeterminado
    Route::get('estudiante/can-scan', [AsistenciaSesionController::class, 'estudianteCanScan']);

    // UX: mostrar/ocultar botón "Llamar asistencia" (docente/admin) si tiene materias SIN QR
    Route::get('docente/can-llamar', [AsistenciaSesionController::class, 'docenteCanLlamar']);

    // Materias del docente/admin con asistencia habilitada (para botón flotante)
    Route::get('mis-materias', [AsistenciaSesionController::class, 'misMaterias']);

    // Materias con sesiones de asistencia registradas (admin/docente)
    Route::get('materias-con-sesiones', [AsistenciaSesionController::class, 'materiasConSesiones']);

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
    Route::post('licencias/aplicar-todas', [LicenciasestudiantesifasController::class, 'aplicarTodas'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::post('licencias/{id}/aplicar', [LicenciasestudiantesifasController::class, 'aplicar'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::put('licencias/{id}', [LicenciasestudiantesifasController::class, 'update'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
    Route::delete('licencias/{id}', [LicenciasestudiantesifasController::class, 'destroy'])->middleware([CheckAbilities::class . ':SECRETARIO(A),RECTOR(A)']);
});
