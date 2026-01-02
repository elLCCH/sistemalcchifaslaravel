<?php

use App\Http\Controllers\AniosController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalificacionesController;
use App\Http\Controllers\InstitucionesController;
use App\Http\Controllers\usuarioslcchsController;
use App\Http\Middleware\CheckAbilities;

use App\Http\Controllers\CarrerasController;
use App\Http\Controllers\ControlesController;
use App\Http\Controllers\EstudiantesIfasController;
use App\Http\Controllers\HistorialInformacionEstudiantesController;
use App\Http\Controllers\InfoEstudiantesIfasController;
use App\Http\Controllers\IniciosController;
use App\Http\Controllers\MateriasController;
use App\Http\Controllers\PlanDeEstudiosController;
use App\Http\Controllers\PlantelAdministrativosController;
use App\Http\Controllers\PlantelDocentesController;
use App\Http\Controllers\PlanteldocentesmateriasController;
use App\Http\Controllers\PagoslcchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TokenController;
use App\Http\Controllers\RegistrocalificacionesController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\CaptureSessionController;
use App\Http\Controllers\CapturePairingController;
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
// Route::middleware("auth:sanctum")->group(function(){
//     Route::resource('usuarioslcchs', usuarioslcchsController::class);
// });



Route::middleware(['auth:sanctum'])->group(function () {
    // =========================
    // AniosController
    // =========================
    Route::get('anios', 'App\Http\Controllers\AniosController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::get('anios/{id}', 'App\Http\Controllers\AniosController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::post('anios', 'App\Http\Controllers\AniosController@store')->middleware(CheckAbilities::class . ':CREADOR');
    Route::put('anios/{id}', 'App\Http\Controllers\AniosController@update')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::delete('anios/{id}', 'App\Http\Controllers\AniosController@destroy')->middleware(CheckAbilities::class . ':CREADOR');

    // =========================
    // usuarioslcchsController
    // =========================
    Route::get('usuarioslcchs', 'App\Http\Controllers\usuarioslcchsController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::get('usuarioslcchs/{id}', 'App\Http\Controllers\usuarioslcchsController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::post('usuarioslcchs', 'App\Http\Controllers\usuarioslcchsController@store')->middleware(CheckAbilities::class . ':CREADOR');
    Route::put('usuarioslcchs/{id}', 'App\Http\Controllers\usuarioslcchsController@update')->middleware(CheckAbilities::class . ':CREADOR');
    Route::delete('usuarioslcchs/{id}', 'App\Http\Controllers\usuarioslcchsController@destroy')->middleware(CheckAbilities::class . ':CREADOR');

    // =========================
    // InstitucionesController
    // =========================
    Route::get('instituciones', 'App\Http\Controllers\InstitucionesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::get('instituciones/{id}', 'App\Http\Controllers\InstitucionesController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO');
    Route::post('instituciones', 'App\Http\Controllers\InstitucionesController@store')->middleware(CheckAbilities::class . ':CREADOR');
    Route::put('instituciones/{id}', 'App\Http\Controllers\InstitucionesController@update')->middleware(CheckAbilities::class . ':CREADOR');
    Route::delete('instituciones/{id}', 'App\Http\Controllers\InstitucionesController@destroy')->middleware(CheckAbilities::class . ':CREADOR');

    // =========================
    // CalificacionesController
    // =========================
    Route::get('calificaciones', 'App\Http\Controllers\CalificacionesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::get('calificaciones/{id}', 'App\Http\Controllers\CalificacionesController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::post('calificaciones', 'App\Http\Controllers\CalificacionesController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::put('calificaciones/{id}', 'App\Http\Controllers\CalificacionesController@update')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::delete('calificaciones/{id}', 'App\Http\Controllers\CalificacionesController@destroy')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::get('calificaciones/by-info/{infoId}', 'App\Http\Controllers\CalificacionesController@byInfo')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::post('calificaciones/bulk-update', 'App\Http\Controllers\CalificacionesController@bulkUpdate')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::get('calificaciones/by-materia/{materiaId}', 'App\Http\Controllers\CalificacionesController@byMateria')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::post('calificaciones/bulk-update-materia', 'App\Http\Controllers\CalificacionesController@bulkUpdateMateria')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::post('calificaciones/assign', 'App\Http\Controllers\CalificacionesController@assign')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/unassign', 'App\Http\Controllers\CalificacionesController@unassign')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/assign-bulk-curso', 'App\Http\Controllers\CalificacionesController@assignBulkCurso')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/assign-bulk-categoria', 'App\Http\Controllers\CalificacionesController@assignBulkCategoria')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/unassign-bulk-categoria', 'App\Http\Controllers\CalificacionesController@unassignBulkCategoria')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/assign-bulk-anio-resolucion', 'App\Http\Controllers\CalificacionesController@assignBulkAnioResolucion')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/unassign-bulk-anio-resolucion', 'App\Http\Controllers\CalificacionesController@unassignBulkAnioResolucion')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');
    Route::post('calificaciones/unassign-all', 'App\Http\Controllers\CalificacionesController@unassignAll')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES');

    // =========================
    // CarrerasController
    // =========================
    Route::get('carreras', 'App\Http\Controllers\CarrerasController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)');
    Route::get('carreras/{id}', 'App\Http\Controllers\CarrerasController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('carreras', 'App\Http\Controllers\CarrerasController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::put('carreras/{id}', 'App\Http\Controllers\CarrerasController@update')->middleware(CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::delete('carreras/{id}', 'App\Http\Controllers\CarrerasController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');

    // =========================
    // ControlesController
    // =========================
    Route::get('controles/options-bulk', 'App\Http\Controllers\ControlesController@optionsBulk')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,DOCENTE,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE');
    Route::get('controles', 'App\Http\Controllers\ControlesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::get('controles/{id}', 'App\Http\Controllers\ControlesController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('controles', 'App\Http\Controllers\ControlesController@store')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::put('controles/{id}', 'App\Http\Controllers\ControlesController@update')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::delete('controles/{id}', 'App\Http\Controllers\ControlesController@destroy')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');

    // =========================
    // EstudiantesIfasController
    // =========================
    Route::get('estudiantesifas', 'App\Http\Controllers\EstudiantesIfasController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE');
    Route::get('estudiantesifas/{id}', 'App\Http\Controllers\EstudiantesIfasController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE');
    Route::post('estudiantesifas', 'App\Http\Controllers\EstudiantesIfasController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES');
    Route::put('estudiantesifas/{id}', 'App\Http\Controllers\EstudiantesIfasController@update')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_DE_TALLERES');
    Route::delete('estudiantesifas/{id}', 'App\Http\Controllers\EstudiantesIfasController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),INSCRIPCIÓN_DE_TALLERES');

    // =========================
    // HistorialInformacionEstudiantesController
    // =========================
    Route::get('historialinformacionestudiantes', 'App\Http\Controllers\HistorialInformacionEstudiantesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::get('historialinformacionestudiantes/{id}', 'App\Http\Controllers\HistorialInformacionEstudiantesController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('historialinformacionestudiantes', 'App\Http\Controllers\HistorialInformacionEstudiantesController@store')->middleware(CheckAbilities::class . ':CREADOR');
    Route::put('historialinformacionestudiantes/{id}', 'App\Http\Controllers\HistorialInformacionEstudiantesController@update')->middleware(CheckAbilities::class . ':CREADOR');
    Route::delete('historialinformacionestudiantes/{id}', 'App\Http\Controllers\HistorialInformacionEstudiantesController@destroy')->middleware(CheckAbilities::class . ':CREADOR');

    // =========================
    // InfoEstudiantesIfasController
    // =========================
    Route::get('infoestudiantesifas/by-estudiante/{estudianteId}', 'App\Http\Controllers\InfoEstudiantesIfasController@byEstudiante')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::get('infoestudiantesifas/pendientes-asignacion', 'App\Http\Controllers\InfoEstudiantesIfasController@pendientesAsignacion')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::get('infoestudiantesifas/estadisticas', 'App\Http\Controllers\InfoEstudiantesIfasController@estadisticas')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::get('infoestudiantesifas', 'App\Http\Controllers\InfoEstudiantesIfasController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::get('infoestudiantesifas/{id}', 'App\Http\Controllers\InfoEstudiantesIfasController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,PRACTICANTE');
    Route::post('infoestudiantesifas', 'App\Http\Controllers\InfoEstudiantesIfasController@store')->middleware(CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA');
    Route::put('infoestudiantesifas/{id}', 'App\Http\Controllers\InfoEstudiantesIfasController@update')->middleware(CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),INSCRIPCIÓN_GESTIÓN_ACADÉMICA,');
    Route::delete('infoestudiantesifas/{id}', 'App\Http\Controllers\InfoEstudiantesIfasController@destroy')->middleware(CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');

    // =========================
    // IniciosController
    // =========================
    Route::get('inicios/{id}', 'App\Http\Controllers\IniciosController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('inicios', 'App\Http\Controllers\IniciosController@store')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::put('inicios/{id}', 'App\Http\Controllers\IniciosController@update')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::delete('inicios/{id}', 'App\Http\Controllers\IniciosController@destroy')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');

    // =========================
    // MateriasController
    // =========================
    Route::put('materias/bulk/paralelo', 'App\Http\Controllers\MateriasController@bulkUpdateParalelo')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::post('materias/bulk/agregar', 'App\Http\Controllers\MateriasController@bulkAddCursoParalelo')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::post('materias/bulk/eliminar', 'App\Http\Controllers\MateriasController@bulkDeleteCursoParalelo')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::get('materias', 'App\Http\Controllers\MateriasController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A)');
    Route::get('materias/{id}', 'App\Http\Controllers\MateriasController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A)');
    Route::post('materias', 'App\Http\Controllers\MateriasController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::put('materias/{id}', 'App\Http\Controllers\MateriasController@update')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::delete('materias/{id}', 'App\Http\Controllers\MateriasController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::get('materias/by-info/{infoId}', 'App\Http\Controllers\MateriasController@byInfo')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE');

    // =========================
    // PagoslcchController
    // =========================
    Route::get('pagoslcch/gestiones-asignaciones', 'App\Http\Controllers\PagoslcchController@gestionesAsignaciones')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::get('pagoslcch', 'App\Http\Controllers\PagoslcchController@index')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::get('pagoslcch/by-info/{infoId}', 'App\Http\Controllers\PagoslcchController@byInfo')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::get('pagoslcch/deuda/by-info/{infoId}', 'App\Http\Controllers\PagoslcchController@deudaByInfo')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::get('pagoslcch/deudores', 'App\Http\Controllers\PagoslcchController@deudores')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::get('pagoslcch/{id}', 'App\Http\Controllers\PagoslcchController@show')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::post('pagoslcch', 'App\Http\Controllers\PagoslcchController@store')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::put('pagoslcch/{id}', 'App\Http\Controllers\PagoslcchController@update')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');
    Route::delete('pagoslcch/{id}', 'App\Http\Controllers\PagoslcchController@destroy')->middleware(CheckAbilities::class . ':CREADOR,SECRETARIO(A)');

    // =========================
    // PlanDeEstudiosController
    // =========================
    Route::get('plandeestudios', 'App\Http\Controllers\PlanDeEstudiosController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::get('plandeestudios/{id}', 'App\Http\Controllers\PlanDeEstudiosController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('plandeestudios', 'App\Http\Controllers\PlanDeEstudiosController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::put('plandeestudios/{id}', 'App\Http\Controllers\PlanDeEstudiosController@update')->middleware(CheckAbilities::class . ':CREADOR,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::delete('plandeestudios/{id}', 'App\Http\Controllers\PlanDeEstudiosController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');

    // =========================
    // PlantelAdministrativosController
    // =========================
    Route::get('planteladministrativos', 'App\Http\Controllers\PlantelAdministrativosController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::get('planteladministrativos/{id}', 'App\Http\Controllers\PlantelAdministrativosController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::post('planteladministrativos', 'App\Http\Controllers\PlantelAdministrativosController@store')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A)');
    Route::put('planteladministrativos/{id}', 'App\Http\Controllers\PlantelAdministrativosController@update')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::delete('planteladministrativos/{id}', 'App\Http\Controllers\PlantelAdministrativosController@destroy')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,DIRECTOR(A)_ACADÉMICO(A)');

    // =========================
    // PlantelDocentesController
    // =========================
    Route::get('planteldocentes', 'App\Http\Controllers\PlantelDocentesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)');
    Route::get('planteldocentes/{id}', 'App\Http\Controllers\PlantelDocentesController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)');
    Route::post('planteldocentes', 'App\Http\Controllers\PlantelDocentesController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::put('planteldocentes/{id}', 'App\Http\Controllers\PlantelDocentesController@update')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A)');
    Route::delete('planteldocentes/{id}', 'App\Http\Controllers\PlantelDocentesController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');

    // =========================
    // PlanteldocentesmateriasController
    // =========================
    Route::get('planteldocentesmaterias', 'App\Http\Controllers\PlanteldocentesmateriasController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)');
    Route::get('planteldocentesmaterias/{id}', 'App\Http\Controllers\PlanteldocentesmateriasController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A)');
    Route::post('planteldocentesmaterias', 'App\Http\Controllers\PlanteldocentesmateriasController@store')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::put('planteldocentesmaterias/{id}', 'App\Http\Controllers\PlanteldocentesmateriasController@update')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::delete('planteldocentesmaterias/{id}', 'App\Http\Controllers\PlanteldocentesmateriasController@destroy')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::post('planteldocentesmaterias/assign', 'App\Http\Controllers\PlanteldocentesmateriasController@assign')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');
    Route::post('planteldocentesmaterias/unassign', 'App\Http\Controllers\PlanteldocentesmateriasController@unassign')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A)');

    // =========================
    // RegistrocalificacionesController
    // =========================
    Route::get('registrocalificaciones/materia/{materiaId}', 'App\Http\Controllers\RegistrocalificacionesController@index')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),PRACTICANTE,OTRO(A),DOCENTE');
    Route::put('registrocalificaciones/evaluacion', 'App\Http\Controllers\RegistrocalificacionesController@updateEvaluacion')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::post('registrocalificaciones/rubros', 'App\Http\Controllers\RegistrocalificacionesController@storeRubro')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::put('registrocalificaciones/rubros/{rubroId}', 'App\Http\Controllers\RegistrocalificacionesController@updateRubro')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::delete('registrocalificaciones/rubros/{rubroId}', 'App\Http\Controllers\RegistrocalificacionesController@deleteRubro')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');
    Route::post('registrocalificaciones/bulk-save', 'App\Http\Controllers\RegistrocalificacionesController@bulkSave')->middleware(CheckAbilities::class . ':CREADOR,DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),DOCENTE');

    // =========================
    // FileUploadController
    // =========================
    Route::post('uploadFile', 'App\Http\Controllers\FileUploadController@uploadFile')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::post('deleteFile', 'App\Http\Controllers\FileUploadController@deleteFile')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),DOCENTE,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');

    // =========================
    // CaptureSessionController
    // =========================
    Route::post('capture-sessions', 'App\Http\Controllers\CaptureSessionController@store')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::get('capture-sessions/{token}', 'App\Http\Controllers\CaptureSessionController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::post('capture-sessions/{token}/cancel', 'App\Http\Controllers\CaptureSessionController@cancel')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');

    // =========================
    // CapturePairingController
    // =========================
    Route::post('capture-pairings', 'App\Http\Controllers\CapturePairingController@store')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::get('capture-pairings/{token}', 'App\Http\Controllers\CapturePairingController@show')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::post('capture-pairings/{token}/request-capture', 'App\Http\Controllers\CapturePairingController@requestCapture')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');
    Route::post('capture-pairings/{token}/cancel-capture', 'App\Http\Controllers\CapturePairingController@cancelCapture')->middleware(CheckAbilities::class . ':CREADOR,TÉCNICO,RECTOR(A),DIRECTOR(A)_ACADÉMICO(A),SECRETARIO(A),ADMINISTRADOR(A),CONSERJE,PORTERO(A),PRACTICANTE,OTRO(A),NINGUNA,INSCRIPCIÓN_GESTIÓN_ACADÉMICA,ASIGNADOR_DE_MATERIAS_ESTUDIANTES,INSCRIPCIÓN_DE_EVENTOS,INSCRIPCIÓN_DE_TALLERES,PRACTICANTE,DOCENTE_DE_TALLER');

});
// RUTAS API PUBLICAS DE CARGA SIN INICIAR SESION
Route::get('/inicios', [IniciosController::class, 'index']);

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
