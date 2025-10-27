<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\usuarioslcchsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


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
Route::resource('usuarioslcchs', usuarioslcchsController::class);
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
