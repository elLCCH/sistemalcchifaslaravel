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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TokenController;
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
    //planteladministrativos
    Route::get('/planteladministrativos', [PlantelAdministrativosController::class, 'index'])->middleware([CheckAbilities::class . ':RECTOR(A),SECRETARIO(A)']);
    Route::get('/planteladministrativos/{id}', [PlantelAdministrativosController::class, 'show'])->middleware([CheckAbilities::class . ':RECTOR(A),SECRETARIO(A)']);
    Route::post('/planteladministrativos', [PlantelAdministrativosController::class, 'store'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::put('/planteladministrativos/{id}', [PlantelAdministrativosController::class, 'update'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::delete('/planteladministrativos/{id}', [PlantelAdministrativosController::class, 'destroy'])->middleware([CheckAbilities::class . ':RECTOR(A)']);



    Route::resource('usuarioslcchs', usuarioslcchsController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('anios', AniosController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('instituciones', InstitucionesController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('calificaciones', CalificacionesController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);

    Route::resource('carreras', CarrerasController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('controles', ControlesController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('estudiantesifas', EstudiantesIfasController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('historialinformacionestudiantes', HistorialInformacionEstudiantesController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('infoestudiantesifas', InfoEstudiantesIfasController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);

    // Obtener inscripciones/info por estudiante (para modal de Visualizar)
    Route::get('infoestudiantesifas/by-estudiante/{estudianteId}', [InfoEstudiantesIfasController::class, 'byEstudiante'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('inicios', IniciosController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('materias', MateriasController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::get('materias/by-info/{infoId}', [MateriasController::class, 'byInfo'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('plandeestudios', PlanDeEstudiosController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('planteldocentes', PlantelDocentesController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::resource('planteldocentesmaterias', PlantelDocentesMateriasController::class)->middleware([CheckAbilities::class . ':RECTOR(A)']);

    // Asignación 1-click docente <-> materia (por llaves)
    Route::post('planteldocentesmaterias/assign', [PlanteldocentesmateriasController::class, 'assign'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('planteldocentesmaterias/unassign', [PlanteldocentesmateriasController::class, 'unassign'])->middleware([CheckAbilities::class . ':RECTOR(A)']);

    // Asignación 1-click estudiante(inscripción) <-> materia (usa calificaciones)
    Route::get('calificaciones/by-info/{infoId}', [CalificacionesController::class, 'byInfo'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/assign', [CalificacionesController::class, 'assign'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/unassign', [CalificacionesController::class, 'unassign'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/assign-bulk-curso', [CalificacionesController::class, 'assignBulkCurso'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/assign-bulk-categoria', [CalificacionesController::class, 'assignBulkCategoria'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/unassign-bulk-categoria', [CalificacionesController::class, 'unassignBulkCategoria'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
    Route::post('calificaciones/unassign-all', [CalificacionesController::class, 'unassignAll'])->middleware([CheckAbilities::class . ':RECTOR(A)']);
});



// ============================================================
// RUTAS API PARA EL SISTEMA DOCS LCCH
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
