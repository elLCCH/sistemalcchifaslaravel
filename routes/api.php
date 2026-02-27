<?php

use App\Http\Controllers\AniosController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalifhistoriasConsultaController;
use App\Http\Controllers\CalifhistoriasController;
use App\Http\Controllers\CalificacionesController;
use App\Http\Controllers\InstitucionesController;
use App\Http\Controllers\UsuarioslcchsController;
use App\Http\Middleware\CheckAbilities;

use App\Http\Controllers\CarrerasController;
use App\Http\Controllers\ControlesController;
use App\Http\Controllers\PlantillasExcelController;
use App\Http\Controllers\EstudiantesifasController;
use App\Http\Controllers\HistorialinformacionestudiantesController;
use App\Http\Controllers\InfoestudiantesifasController;
use App\Http\Controllers\IniciosController;
use App\Http\Controllers\MateriasController;
use App\Http\Controllers\PlandeestudiosController;
use App\Http\Controllers\PlanteladministrativosController;
use App\Http\Controllers\PlanteldocentesController;
use App\Http\Controllers\PlanteldocentesmateriasController;
use App\Http\Controllers\PagoslcchController;
use App\Http\Controllers\TalleristasController;
use App\Http\Controllers\PagostalleresController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\EstudianteseventosController;
use App\Http\Controllers\PublicEventosController;
use App\Http\Controllers\DiseniocertificadopdfsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TokenController;
use App\Http\Controllers\RegistroCalificacionesController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\CaptureSessionController;
use App\Http\Controllers\CapturePairingController;
use App\Http\Controllers\InfoauditoriaController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\PublicHorariosController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\BibliotecaarchivoslcchController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PeticionesPrivadas;
use App\Http\Controllers\PeticionesPublicas;
use App\Http\Controllers\EstadisticasAsignacionesController;
use App\Http\Middleware\AuditMiddleware;

Route::post('/verify-token', [TokenController::class, 'verify']);
Route::prefix("v1/auth")->group(function(){ //el prefijo vi/auth funciona como el routing de angular: v1/auth/login
    Route::post('/login', [AuthController::class, "login"]); //EJECUTAR LA FUNCION login desde el authcontroller
    Route::post('/logout', [AuthController::class, 'logout']); //v1/auth/logout
    // Route::post('/register', [AuthController::class, 'register']); //v1/auth/register
    // Route::post('/reset-password', [AuthController::class, 'resetPassword']); //v1/auth/reset-password
    // Route::post('/change-password', [AuthController::class, 'changePassword']); //v1/auth/change-password
    // Route::post('/forgot-password', [AuthController::class, 'forgotPassword']); //v1/auth/forgot-password
    // Route::post('/verify-email', [AuthController::class, 'verifyEmail']); //v1/auth/verify-email
    // Route::post('/resend-verification', [AuthController::class, 'resendVerification']); //v1/auth/resend-verification
    // Route::post('/update-profile', [AuthController::class, 'updateProfile']); //v1/auth/update-profile
    Route::post('/cambiar-clave', [AuthController::class, 'cambiarClave'])->middleware('auth:sanctum'); //cambiar clave de usuario ESTO SUELE SER PARA PERMITIR EL AUTORIZADO
    Route::get('/user', [AuthController::class, 'getUser'])->middleware('auth:sanctum'); //v1/auth/user
});

// ============================================================
// Seguridad: Google Authenticator (TOTP)
// Vinculación/desvinculación para el usuario autenticado
// ============================================================
Route::prefix('v1/security')->middleware('auth:sanctum')->group(function () {
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/enroll/start', [TwoFactorController::class, 'enrollStart']);
    Route::post('/2fa/enroll/confirm', [TwoFactorController::class, 'enrollConfirm']);
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);
});

// ============================================================
// Recuperación de contraseña (público) usando código TOTP
// Nota: Google Authenticator NO recibe códigos; el usuario ingresa su TOTP.
// ============================================================
Route::prefix('v1/password-recovery')->group(function () {
    Route::post('/start', [PasswordRecoveryController::class, 'start']);
    Route::post('/reset', [PasswordRecoveryController::class, 'reset']);
});

Route::prefix('v1/perfil')->middleware('auth:sanctum')->group(function () {
    Route::get('/materias', [PerfilController::class, 'materias']);
    Route::get('/deudas', [PerfilController::class, 'deudas']);
    Route::put('/materias/{materiaId}', [PerfilController::class, 'updateMateriaDocente']);
});

Route::prefix('v1/branding')->middleware('auth:sanctum')->group(function () {
    Route::get('/assets', 'App\\Http\\Controllers\\BrandingController@assets');
    // Endpoints legacy (string)
    Route::get('/ObtenerLogo', 'App\\Http\\Controllers\\BrandingController@ObtenerLogo');
    Route::get('/ObtenerMinisterio', 'App\\Http\\Controllers\\BrandingController@ObtenerMinisterio');
    Route::get('/ObtenerMinisterioEscudo', 'App\\Http\\Controllers\\BrandingController@ObtenerMinisterioEscudo');
});
// Route::middleware("auth:sanctum")->group(function(){
//     Route::resource('usuarioslcchs', usuarioslcchsController::class);
// });



Route::middleware(['auth:sanctum', AuditMiddleware::class])->group(function () {
    // =========================
    // InfoauditoriaController
    // =========================
    Route::get('/infoauditoria', [InfoauditoriaController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO']);
    Route::get('/infoauditoria/{id}', [InfoauditoriaController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO']);

    // =========================
    // AniosController
    // =========================
    
    Route::get('/anios/{id}', [AniosController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::post('/anios', [AniosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::put('/anios/{id}', [AniosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO']);
    Route::delete('/anios/{id}', [AniosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR']);
    
    // =========================
    // UsuarioslcchsController
    // =========================
    Route::get('/usuarioslcchs', [UsuarioslcchsController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO']);
    Route::get('/usuarioslcchs/{id}', [UsuarioslcchsController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO']);
    Route::post('/usuarioslcchs', [UsuarioslcchsController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::put('/usuarioslcchs/{id}', [UsuarioslcchsController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::delete('/usuarioslcchs/{id}', [UsuarioslcchsController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR']);
    
    // =========================
    // InstitucionesController
    // =========================
    
    Route::post('/instituciones', [InstitucionesController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::put('/instituciones/{id}', [InstitucionesController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::delete('/instituciones/{id}', [InstitucionesController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR']);


    // =========================
    // AuthController
    // =========================
    // (No routes in this block)

    // =========================
    // CalificacionesController
    // =========================
    Route::get('/calificaciones', [CalificacionesController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('/calificaciones/{id}', [CalificacionesController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::post('/calificaciones', [CalificacionesController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::put('/calificaciones/{id}', [CalificacionesController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::delete('/calificaciones/{id}', [CalificacionesController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::get('calificaciones/by-info/{infoId}', [CalificacionesController::class, 'byInfo'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::post('calificaciones/bulk-update', [CalificacionesController::class, 'bulkUpdate'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::get('calificaciones/by-materia/{materiaId}', [CalificacionesController::class, 'byMateria'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('calificaciones/materia-token/{materiaId}', [CalificacionesController::class, 'materiaToken'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('calificaciones/by-materia-token', [CalificacionesController::class, 'byMateriaToken'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::post('calificaciones/bulk-update-materia', [CalificacionesController::class, 'bulkUpdateMateria'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::post('calificaciones/assign', [CalificacionesController::class, 'assign'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/unassign', [CalificacionesController::class, 'unassign'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
	Route::post('calificaciones/update-estado-registro', [CalificacionesController::class, 'updateEstadoRegistro'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/assign-bulk-curso', [CalificacionesController::class, 'assignBulkCurso'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/assign-bulk-categoria', [CalificacionesController::class, 'assignBulkCategoria'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/unassign-bulk-categoria', [CalificacionesController::class, 'unassignBulkCategoria'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/assign-bulk-anio-resolucion', [CalificacionesController::class, 'assignBulkAnioResolucion'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/unassign-bulk-anio-resolucion', [CalificacionesController::class, 'unassignBulkAnioResolucion'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('calificaciones/unassign-all', [CalificacionesController::class, 'unassignAll'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // =========================
    // Estadísticas (Asignaciones por materias)
    // =========================
    Route::get('estadisticas/asignaciones/materias', [EstadisticasAsignacionesController::class, 'porMaterias'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::get('estadisticas/asignaciones/materias/estudiantes', [EstadisticasAsignacionesController::class, 'estudiantesAsignados'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // =========================
    // Estadísticas (Docentes asignados vs ModoMateria)
    // =========================
    Route::get('estadisticas/docentes/opciones-resolucion', [EstadisticasAsignacionesController::class, 'opcionesResolucionNivel'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::get('estadisticas/docentes/asignados', [EstadisticasAsignacionesController::class, 'docentesAsignados'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::get('estadisticas/docentes/asignados/estudiantes', [EstadisticasAsignacionesController::class, 'docentesAsignadosEstudiantes'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // =========================
    // CarrerasController
    // =========================
    Route::get('/carreras', [CarrerasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)']);
    Route::get('/carreras/{id}', [CarrerasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/carreras', [CarrerasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::put('/carreras/{id}', [CarrerasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/carreras/{id}', [CarrerasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);

    // =========================
    // ControlesController
    // =========================
    Route::get('controles/options-bulk', [ControlesController::class, 'optionsBulk'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,ESTUDIANTE']);
    Route::get('/controles/{id}', [ControlesController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/controles', [ControlesController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::put('/controles/{id}', [ControlesController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/controles/{id}', [ControlesController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);

    // =========================
    // PlantillasExcelController (CRUD independiente; tabla controles)
    // =========================
    Route::get('/plantillas-excel', [PlantillasExcelController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::get('/plantillas-excel/{id}', [PlantillasExcelController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/plantillas-excel', [PlantillasExcelController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::put('/plantillas-excel/{id}', [PlantillasExcelController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/plantillas-excel/{id}', [PlantillasExcelController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/plantillas-excel/{id}/generar', [PlantillasExcelController::class, 'generar'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('/plantillas-excel/{id}/actualizar', [PlantillasExcelController::class, 'actualizar'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // =========================
    // EstudiantesifasController
    // =========================
    Route::get('/estudiantesifas', [EstudiantesifasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE']);
    Route::get('/estudiantesifas/{id}', [EstudiantesifasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE']);
    Route::post('/estudiantesifas', [EstudiantesifasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);
    Route::put('/estudiantesifas/{id}', [EstudiantesifasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_DE_TALLERES']);
    Route::delete('/estudiantesifas/{id}', [EstudiantesifasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);

    // =========================
    // HistorialinformacionestudiantesController
    // =========================
    Route::get('/historialinformacionestudiantes', [HistorialinformacionestudiantesController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::get('/historialinformacionestudiantes/{id}', [HistorialinformacionestudiantesController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/historialinformacionestudiantes', [HistorialinformacionestudiantesController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::put('/historialinformacionestudiantes/{id}', [HistorialinformacionestudiantesController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR']);
    Route::delete('/historialinformacionestudiantes/{id}', [HistorialinformacionestudiantesController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR']);

    // =========================
    // InfoestudiantesifasController
    // =========================
    Route::get('infoestudiantesifas/by-estudiante/{estudianteId}', [InfoestudiantesifasController::class, 'byEstudiante'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('infoestudiantesifas/pendientes-asignacion', [InfoestudiantesifasController::class, 'pendientesAsignacion'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('infoestudiantesifas/estadisticas', [InfoestudiantesifasController::class, 'estadisticas'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('infoestudiantesifas/estadisticas-detalle', [InfoestudiantesifasController::class, 'estadisticasDetalle'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('infoestudiantesifas/conteo-docentes', [InfoestudiantesifasController::class, 'conteoDocentes'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE,DOCENTE']);
    Route::get('infoestudiantesifas/options-cursos-docente', [InfoestudiantesifasController::class, 'optionsCursosDocente'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::get('/infoestudiantesifas', [InfoestudiantesifasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE,DOCENTE']);
    Route::post('/infoestudiantesifas', [InfoestudiantesifasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA']);

    // Importante: rutas “especiales” antes que /{id} para evitar colisiones
    Route::put('/infoestudiantesifas/bulk-docente', [InfoestudiantesifasController::class, 'bulkUpdateDocente'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,']);

    // Acciones grupales: asignación automática por historial + rollback
    Route::post('/infoestudiantesifas/acciones-grupales/asignar-materias', [InfoestudiantesifasController::class, 'accionesGrupalesAsignarMaterias'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);
    Route::post('/infoestudiantesifas/acciones-grupales/rollback-asignacion', [InfoestudiantesifasController::class, 'accionesGrupalesRollbackAsignacion'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // Verificación masiva: solicitado vs asignación oficial (Año + Resolución/Nivel)
    Route::post('/infoestudiantesifas/acciones-grupales/verificar-asignacion', [InfoestudiantesifasController::class, 'accionesGrupalesVerificarAsignacion'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    // Acciones grupales: modificar inscripciones (curso/paralelo/turno + quitar asignaciones)
    Route::post('/infoestudiantesifas/acciones-grupales/modificar-inscripciones', [InfoestudiantesifasController::class, 'accionesGrupalesModificarInscripciones'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES']);

    Route::get('/infoestudiantesifas/{id}', [InfoestudiantesifasController::class, 'show'])->whereNumber('id')->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE']);
    Route::put('/infoestudiantesifas/{id}', [InfoestudiantesifasController::class, 'update'])->whereNumber('id')->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,']);
    Route::delete('/infoestudiantesifas/{id}', [InfoestudiantesifasController::class, 'destroy'])->whereNumber('id')->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);

    // =========================
    // IniciosController
    // =========================
    // Route::get('/inicios', [IniciosController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    
    // Solo plantel administrativo (y super) debería publicar información en inicios
    Route::post('/inicios', [IniciosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::put('/inicios/{id}', [IniciosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/inicios/{id}', [IniciosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);

    // =========================
    // MateriasController
    // =========================
    Route::put('materias/bulk/paralelo', [MateriasController::class, 'bulkUpdateParalelo'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::post('materias/bulk/agregar', [MateriasController::class, 'bulkAddCursoParalelo'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::post('materias/bulk/eliminar', [MateriasController::class, 'bulkDeleteCursoParalelo'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::get('/materias', [MateriasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A)']);
    Route::get('/materias/{id}', [MateriasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A)']);
    Route::post('/materias', [MateriasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::put('/materias/{id}', [MateriasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::delete('/materias/{id}', [MateriasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::get('materias/by-info/{infoId}', [MateriasController::class, 'byInfo'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE']);

    // =========================
    // PagoslcchController
    // =========================
    Route::get('pagoslcch/gestiones-asignaciones', [PagoslcchController::class, 'gestionesAsignaciones'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch', [PagoslcchController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch/by-info/{infoId}', [PagoslcchController::class, 'byInfo'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch/deuda/by-info/{infoId}', [PagoslcchController::class, 'deudaByInfo'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch/deudores', [PagoslcchController::class, 'deudores'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch/pagadores', [PagoslcchController::class, 'pagadores'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::get('pagoslcch/{id}', [PagoslcchController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::post('pagoslcch', [PagoslcchController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::put('pagoslcch/{id}', [PagoslcchController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);
    Route::delete('pagoslcch/{id}', [PagoslcchController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A)']);

    // =========================
    // TalleristasController
    // =========================
    Route::get('/talleristas', [TalleristasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::get('/talleristas/{id}', [TalleristasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::post('/talleristas', [TalleristasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);
    Route::put('/talleristas/{id}', [TalleristasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);
    Route::delete('/talleristas/{id}', [TalleristasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);

    // =========================
    // PagostalleresController
    // =========================
    Route::get('/pagostalleres', [PagostalleresController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::get('/pagostalleres/by-tallerista/{talleristaId}', [PagostalleresController::class, 'byTallerista'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::get('/pagostalleres/{id}', [PagostalleresController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::post('/pagostalleres', [PagostalleresController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::put('/pagostalleres/{id}', [PagostalleresController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES,DOCENTE_DE_TALLER']);
    Route::delete('/pagostalleres/{id}', [PagostalleresController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES']);

    // =========================
    // EventosController
    // =========================
    Route::get('/eventos', [EventosController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::get('/eventos/{id}', [EventosController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::post('/eventos', [EventosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);
    Route::put('/eventos/{id}', [EventosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);
    Route::delete('/eventos/{id}', [EventosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);

    // =========================
    // DiseniocertificadopdfsController
    // =========================
    Route::get('/diseniocertificadopdfs', [DiseniocertificadopdfsController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::get('/diseniocertificadopdfs/{id}', [DiseniocertificadopdfsController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::post('/diseniocertificadopdfs', [DiseniocertificadopdfsController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);
    Route::put('/diseniocertificadopdfs/{id}', [DiseniocertificadopdfsController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);
    Route::delete('/diseniocertificadopdfs/{id}', [DiseniocertificadopdfsController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO']);

    // =========================
    // EstudianteseventosController
    // =========================
    Route::get('/estudianteseventos', [EstudianteseventosController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::get('/estudianteseventos/by-evento/{eventoId}', [EstudianteseventosController::class, 'byEvento'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::get('/estudianteseventos/{id}', [EstudianteseventosController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::post('/estudianteseventos', [EstudianteseventosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::put('/estudianteseventos/{id}', [EstudianteseventosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::delete('/estudianteseventos/{id}', [EstudianteseventosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);
    Route::post('/estudianteseventos/{id}/generar-certificado', [EstudianteseventosController::class, 'generarCertificado'])->middleware([CheckAbilities::class . ':CREADOR,SECRETARIO(A),TÉCNICO,INSCRIPCIÓN_DE_EVENTOS']);

    // =========================
    // PlandeestudiosController
    // =========================
    Route::get('/plandeestudios', [PlandeestudiosController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::get('/plandeestudios/{id}', [PlandeestudiosController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/plandeestudios', [PlandeestudiosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::put('/plandeestudios/{id}', [PlandeestudiosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/plandeestudios/{id}', [PlandeestudiosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);

    // =========================
    // PlanteladministrativosController
    // =========================
    Route::get('/planteladministrativos', [PlanteladministrativosController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::get('/planteladministrativos/{id}', [PlanteladministrativosController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,RECTOR(A),TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::post('/planteladministrativos', [PlanteladministrativosController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A)']);
    Route::put('/planteladministrativos/{id}', [PlanteladministrativosController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::delete('/planteladministrativos/{id}', [PlanteladministrativosController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A)']);

    // =========================
    // PlanteldocentesController
    // =========================
    Route::get('/planteldocentes', [PlanteldocentesController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/planteldocentes/{id}', [PlanteldocentesController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)']);
    Route::post('/planteldocentes', [PlanteldocentesController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::put('/planteldocentes/{id}', [PlanteldocentesController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)']);
    Route::delete('/planteldocentes/{id}', [PlanteldocentesController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);

    // =========================
    // PlanteldocentesmateriasController
    // =========================
    Route::get('/planteldocentesmaterias', [PlanteldocentesmateriasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)']);
    Route::get('/planteldocentesmaterias/{id}', [PlanteldocentesmateriasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)']);
    Route::post('/planteldocentesmaterias', [PlanteldocentesmateriasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::put('/planteldocentesmaterias/{id}', [PlanteldocentesmateriasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::delete('/planteldocentesmaterias/{id}', [PlanteldocentesmateriasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::post('/planteldocentesmaterias/assign', [PlanteldocentesmateriasController::class, 'assign'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);
    Route::post('/planteldocentesmaterias/unassign', [PlanteldocentesmateriasController::class, 'unassign'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)']);

    // =========================
    // RegistroCalificacionesController
    // =========================
    Route::get('registrocalificaciones/materia/{materiaId}', [RegistroCalificacionesController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::put('registrocalificaciones/evaluacion', [RegistroCalificacionesController::class, 'updateEvaluacion'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::post('registrocalificaciones/rubros', [RegistroCalificacionesController::class, 'storeRubro'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::put('registrocalificaciones/rubros/{rubroId}', [RegistroCalificacionesController::class, 'updateRubro'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::delete('registrocalificaciones/rubros/{rubroId}', [RegistroCalificacionesController::class, 'deleteRubro'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::post('registrocalificaciones/bulk-save', [RegistroCalificacionesController::class, 'bulkSave'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);

    // =========================
    // FileUploadController (TODOS LOS ROLES QUE PUEDEN SUBIR ARCHIVOS)
    // =========================
    Route::post('uploadFile', [FileUploadController::class, 'uploadFile'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::post('deleteFile', [FileUploadController::class, 'deleteFile'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);

    // =========================
    // BibliotecaarchivoslcchController (Biblioteca digital por institución)
    // =========================
    Route::get('bibliotecaarchivoslcch', [BibliotecaarchivoslcchController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER,ESTUDIANTE']);
    Route::get('bibliotecaarchivoslcch/{id}', [BibliotecaarchivoslcchController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER,ESTUDIANTE']);
    Route::post('bibliotecaarchivoslcch', [BibliotecaarchivoslcchController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE']);
    Route::put('bibliotecaarchivoslcch/{id}', [BibliotecaarchivoslcchController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE']);
    Route::delete('bibliotecaarchivoslcch/{id}', [BibliotecaarchivoslcchController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),DOCENTE']);

    // ============================================================
    // RUTAS API PARA EL SISTEMA DE CAPTURA DE ASISTENCIA
    // =========================
    // CaptureSessionController
    // =========================
    Route::post('capture-sessions', [CaptureSessionController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::get('capture-sessions/{token}', [CaptureSessionController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::post('capture-sessions/{token}/cancel', [CaptureSessionController::class, 'cancel'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);

    // =========================
    // CapturePairingController
    // =========================
    Route::post('capture-pairings', [CapturePairingController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::get('capture-pairings/{token}', [CapturePairingController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::post('capture-pairings/{token}/request-capture', [CapturePairingController::class, 'requestCapture'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);
    Route::post('capture-pairings/{token}/cancel-capture', [CapturePairingController::class, 'cancelCapture'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER']);


    
    // =========================
    // CalifhistoriasController
    // =========================
    Route::get('/califhistorias', [CalifhistoriasController::class, 'index'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);

    // =========================
    // Califhistorias - Consultas (solo lectura)
    // =========================
    Route::get('/califhistorias/opciones/instituciones', [CalifhistoriasConsultaController::class, 'opcionesInstituciones'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/anios', [CalifhistoriasConsultaController::class, 'opcionesAnios'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/mallas', [CalifhistoriasConsultaController::class, 'opcionesMallas'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/niveles', [CalifhistoriasConsultaController::class, 'opcionesNiveles'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/cursos', [CalifhistoriasConsultaController::class, 'opcionesCursos'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/docentes', [CalifhistoriasConsultaController::class, 'opcionesDocentes'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/docentes-especialidad', [CalifhistoriasConsultaController::class, 'opcionesDocentesEspecialidad'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/opciones/cis', [CalifhistoriasConsultaController::class, 'opcionesCIs'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);

    Route::get('/califhistorias/consultas/curso', [CalifhistoriasConsultaController::class, 'porCurso'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/consultas/docente', [CalifhistoriasConsultaController::class, 'porDocente'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/consultas/docente-especialidad', [CalifhistoriasConsultaController::class, 'porDocenteEspecialidad'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/consultas/estudiante', [CalifhistoriasConsultaController::class, 'porEstudiante'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/consultas/estudiantes-por-nombre', [CalifhistoriasConsultaController::class, 'estudiantesPorNombre'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/consultas/estudiantes-por-ci', [CalifhistoriasConsultaController::class, 'estudiantesPorCi'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);

    Route::get('/califhistorias/estadisticas/general', [CalifhistoriasConsultaController::class, 'estadisticasGeneral'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/estadisticas/docente', [CalifhistoriasConsultaController::class, 'estadisticasPorDocente'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/estadisticas/estudiante', [CalifhistoriasConsultaController::class, 'estadisticasPorEstudiante'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::get('/califhistorias/estadisticas/curso', [CalifhistoriasConsultaController::class, 'estadisticasPorCurso'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);

    Route::get('/califhistorias/{id}', [CalifhistoriasController::class, 'show'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::post('/califhistorias', [CalifhistoriasController::class, 'store'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::put('/califhistorias/{id}', [CalifhistoriasController::class, 'update'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);
    Route::delete('/califhistorias/{id}', [CalifhistoriasController::class, 'destroy'])->middleware([CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE']);


    //PETICIONES RANDOM
    
    Route::post('/CargarEstudiantesMateriaPrivado', [PeticionesPrivadas::class, 'CargarEstudiantesMateriaPrivado'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::post('/CargarEstudiantesMezcladosMateriasPrivado', [PeticionesPrivadas::class, 'CargarEstudiantesMezcladosMateriasPrivado'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::post('/CargarInfoPlanesAniosCarrerasInstitucionesPrivado', [PeticionesPrivadas::class, 'CargarInfoPlanesAniosCarrerasInstitucionesPrivado'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::post('/CargarInformacionCuadroInscripciones', [PeticionesPrivadas::class, 'CargarInformacionCuadroInscripciones'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);
    Route::post('/CargarListaPreliminarAlumnos2026', [PeticionesPrivadas::class, 'CargarListaPreliminarAlumnos2026'])->middleware([CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE']);

});


Route::post('/HorariosCursos', [PublicHorariosController::class, 'cursos']);
Route::post('/CargarEstudiantesMateriaPublico', [PeticionesPublicas::class, 'CargarEstudiantesMateriaPublico']);
Route::get('/CargarInfoPublicaDocentes', [PeticionesPublicas::class, 'CargarInfoPublicaDocentes']);
Route::get('/CargarInfoPublicaAdministrativos', [PeticionesPublicas::class, 'CargarInfoPublicaAdministrativos']);
Route::post('/CargarEstudiantesMezcladosMateriasPublico', [PeticionesPublicas::class, 'CargarEstudiantesMezcladosMateriasPublico']);
Route::post('/CargarMateriasEstudiantesMultiInstitucionPublico', [PeticionesPublicas::class, 'CargarMateriasEstudiantesMultiInstitucionPublico']);


Route::post('/ListaEstudiantesCurso', [PublicHorariosController::class, 'estudiantesCurso']);
Route::post('/ListaEstudiantesGestion', [PublicHorariosController::class, 'estudiantesGestion']);
// RUTAS API PUBLICAS DE CARGA SIN INICIAR SESION

Route::get('/anios', [AniosController::class, 'index']);
Route::get('/inicios', [IniciosController::class, 'index']);
Route::get('/inicios/{id}', [IniciosController::class, 'show']);
Route::get('/instituciones', [InstitucionesController::class, 'index']);
Route::get('/instituciones/{id}', [InstitucionesController::class, 'show']);
Route::get('/controles', [ControlesController::class, 'index']);

// ============================================================
// RUTAS PÚBLICAS PARA INSCRIPCIÓN A EVENTOS (WEB)
// ============================================================
Route::prefix('public')->group(function () {
    Route::get('/eventos', [PublicEventosController::class, 'index']);
    Route::get('/eventos/{id}', [PublicEventosController::class, 'show']);
    Route::post('/eventos/{eventoId}/inscripcion', [PublicEventosController::class, 'inscribir']);

    // Horarios (tabla inicios, categoria=HORARIO)
    Route::get('/horarios', [IniciosController::class, 'horariosPublicos']);
});

// ============================================================
// RUTAS API PUBLICAS PARA RECOGER FOTOS DESDE EL CELULAR
// ============================================================
// Upload público por token (para que el celular suba sin login)
Route::post('capture-sessions/{token}/upload', [CaptureSessionController::class, 'upload']);

// Vinculación pública (celular) por token
Route::post('capture-pairings/{token}/link', [CapturePairingController::class, 'link']);
Route::get('capture-pairings/{token}/pending-capture', [CapturePairingController::class, 'pendingCapture']);
Route::post('capture-pairings/{token}/revoke', [CapturePairingController::class, 'revoke']);



// ============================================================
// RUTAS API PARA EL SISTEMADOCS
// Agregar esto en: routes/api.php
// ============================================================

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ArticleController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí se definen todas las rutas de la API REST
|
*/

// ============================================================
// RUTAS DE PROYECTOS
// ============================================================
Route::prefix('projects')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])
        ->name('projects.index')
        ->withoutMiddleware('auth:sanctum'); // Si lo necesitas sin autenticación
    
    Route::post('/', [ProjectController::class, 'store'])
        ->name('projects.store');
    
    Route::get('{id}', [ProjectController::class, 'show'])
        ->name('projects.show')
        ->withoutMiddleware('auth:sanctum');
    
    Route::put('{id}', [ProjectController::class, 'update'])
        ->name('projects.update');
    
    Route::delete('{id}', [ProjectController::class, 'destroy'])
        ->name('projects.destroy');
});

// ============================================================
// RUTAS DE SECCIONES
// ============================================================
Route::prefix('sections')->group(function () {
    // Obtener secciones por proyecto
    Route::get('project/{projectId}', [SectionController::class, 'byProject'])
        ->name('sections.byProject')
        ->withoutMiddleware('auth:sanctum');
    
    Route::post('/', [SectionController::class, 'store'])
        ->name('sections.store');
    
    Route::get('{id}', [SectionController::class, 'show'])
        ->name('sections.show')
        ->withoutMiddleware('auth:sanctum');
    
    Route::put('{id}', [SectionController::class, 'update'])
        ->name('sections.update');
    
    Route::delete('{id}', [SectionController::class, 'destroy'])
        ->name('sections.destroy');
    
    // Reordenar secciones
    Route::post('reorder', [SectionController::class, 'reorder'])
        ->name('sections.reorder');
});

// ============================================================
// RUTAS DE ARTÍCULOS
// ============================================================
Route::prefix('articles')->group(function () {
    // Obtener artículos por sección
    Route::get('section/{sectionId}', [ArticleController::class, 'bySection'])
        ->name('articles.bySection')
        ->withoutMiddleware('auth:sanctum');
    
    Route::post('/', [ArticleController::class, 'store'])
        ->name('articles.store');
    
    Route::get('{id}', [ArticleController::class, 'show'])
        ->name('articles.show')
        ->withoutMiddleware('auth:sanctum');
    
    Route::put('{id}', [ArticleController::class, 'update'])
        ->name('articles.update');
    
    Route::delete('{id}', [ArticleController::class, 'destroy'])
        ->name('articles.destroy');
    
    // Archivos adjuntos
    Route::get('{articleId}/attachments', [ArticleController::class, 'getAttachments'])
        ->name('articles.attachments')
        ->withoutMiddleware('auth:sanctum');
    
    Route::post('{articleId}/upload', [ArticleController::class, 'upload'])
        ->name('articles.upload');
    
    Route::get('{articleId}/attachment/{attachmentId}', [ArticleController::class, 'downloadAttachment'])
        ->name('articles.downloadAttachment')
        ->withoutMiddleware('auth:sanctum');
    
    Route::delete('{articleId}/attachment/{attachmentId}', [ArticleController::class, 'deleteAttachment'])
        ->name('articles.deleteAttachment');
    
    // Reordenar artículos
    Route::post('reorder', [ArticleController::class, 'reorder'])
        ->name('articles.reorder');
});

// ============================================================
// INFORMACIÓN ÚTIL
// ============================================================
/*
 * ESTRUCTURA DE DATOS:
 * 
 * Project (Proyecto)
 *   ├── Sections (Secciones)
 *   │    └── Articles (Artículos)
 *   │         └── Attachments (Archivos)
 * 
 * EJEMPLOS DE LLAMADAS:
 * 
 * 1. PROYECTOS:
 *    GET     /api/projects                  - Obtener todos los proyectos
 *    POST    /api/projects                  - Crear proyecto
 *    GET     /api/projects/1                - Obtener proyecto por ID
 *    PUT     /api/projects/1                - Actualizar proyecto
 *    DELETE  /api/projects/1                - Eliminar proyecto
 * 
 * 2. SECCIONES:
 *    GET     /api/sections/project/1        - Obtener secciones de un proyecto
 *    POST    /api/sections                  - Crear sección
 *    GET     /api/sections/1                - Obtener sección por ID
 *    PUT     /api/sections/1                - Actualizar sección
 *    DELETE  /api/sections/1                - Eliminar sección
 *    POST    /api/sections/reorder          - Reordenar secciones
 * 
 * 3. ARTÍCULOS:
 *    GET     /api/articles/section/1        - Obtener artículos de una sección
 *    POST    /api/articles                  - Crear artículo
 *    GET     /api/articles/1                - Obtener artículo por ID
 *    PUT     /api/articles/1                - Actualizar artículo
 *    DELETE  /api/articles/1                - Eliminar artículo
 *    POST    /api/articles/reorder          - Reordenar artículos
 * 
 * 4. ARCHIVOS ADJUNTOS:
 *    GET     /api/articles/1/attachments    - Obtener archivos de un artículo
 *    POST    /api/articles/1/upload         - Subir archivo
 *    GET     /api/articles/1/attachment/2   - Descargar archivo
 *    DELETE  /api/articles/1/attachment/2   - Eliminar archivo
 * 
 * EJEMPLOS DE PAYLOADS:
 * 
 * POST /api/projects
 * {
 *   "name": "Mi Proyecto",
 *   "description": "Descripción del proyecto"
 * }
 * 
 * POST /api/sections
 * {
 *   "project_id": 1,
 *   "title": "Mi Sección",
 *   "order": 0
 * }
 * 
 * POST /api/articles
 * {
 *   "section_id": 1,
 *   "title": "Mi Artículo",
 *   "content": "# Contenido Markdown",
 *   "order": 0
 * }
 * 
 * POST /api/articles/1/upload (form-data)
 * file: [archivo a subir]
 * 
 * POST /api/sections/reorder
 * [
 *   { "id": 1, "order": 0 },
 *   { "id": 2, "order": 1 }
 * ]
 */














Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ============================================================
// LChaula - Rutas API (Classroom)
// ============================================================
require __DIR__ . '/lchaula_api_routes.php';

require __DIR__ . '/asistencias_api_routes.php';
