<?php

use App\Http\Controllers\AngularController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::any('/{any}', [AngularController::class, 'index'])->where('any', '^(?!api|assets|build|archivos|attachments|favicon\.ico|robots\.txt).*$');

