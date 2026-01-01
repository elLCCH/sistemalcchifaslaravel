<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AulaVirtualController;
use App\Http\Controllers\AulaParticipanteController;
use App\Http\Controllers\PublicacionAulaController;
use App\Http\Controllers\TareaController;
use App\Http\Controllers\EntregaTareaController;
use App\Http\Controllers\CalificacionTareaController;
use App\Http\Controllers\LChaulaArchivoController;

// ============================================================
// RUTAS API PARA LChaula - SISTEMA TIPO CLASSROOM
// Se incluye desde routes/api.php
// Base: /api/lchaula
// ============================================================

Route::prefix('lchaula')->group(function () {
    // Aulas
    Route::get('aulas', [AulaVirtualController::class, 'index']);
    Route::post('aulas', [AulaVirtualController::class, 'store']);
    Route::get('aulas/{id}', [AulaVirtualController::class, 'show']);
    Route::put('aulas/{id}', [AulaVirtualController::class, 'update']);
    Route::delete('aulas/{id}', [AulaVirtualController::class, 'destroy']);

    // Participantes
    Route::get('aulas/{aulaId}/participantes', [AulaParticipanteController::class, 'index']);
    Route::post('aulas/{aulaId}/participantes', [AulaParticipanteController::class, 'store']);
    Route::put('aulas/{aulaId}/participantes/{id}', [AulaParticipanteController::class, 'update']);
    Route::delete('aulas/{aulaId}/participantes/{id}', [AulaParticipanteController::class, 'destroy']);

    // Publicaciones
    Route::get('aulas/{aulaId}/publicaciones', [PublicacionAulaController::class, 'index']);
    Route::post('aulas/{aulaId}/publicaciones', [PublicacionAulaController::class, 'store']);
    Route::put('aulas/{aulaId}/publicaciones/{id}', [PublicacionAulaController::class, 'update']);
    Route::delete('aulas/{aulaId}/publicaciones/{id}', [PublicacionAulaController::class, 'destroy']);

    // Tareas
    Route::get('tareas/{id}', [TareaController::class, 'show']);
    Route::put('tareas/{id}', [TareaController::class, 'update']);

    // Entregas
    Route::get('tareas/{tareaId}/entregas', [EntregaTareaController::class, 'index']);
    Route::post('tareas/{tareaId}/entregar', [EntregaTareaController::class, 'submit']);

    // Calificación
    Route::post('entregas/{entregaId}/calificar', [CalificacionTareaController::class, 'store']);

    // Archivos
    Route::get('archivos', [LChaulaArchivoController::class, 'listByRelacion']);
    Route::post('archivos/upload', [LChaulaArchivoController::class, 'upload']);
    Route::delete('archivos/{id}', [LChaulaArchivoController::class, 'destroy']);
});
