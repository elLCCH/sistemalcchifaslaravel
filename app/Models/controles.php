<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Controles extends Model
{
    use HasApiTokens;
    protected $table = 'controles';
    // Lista de atributos asignables
    protected $fillable = [
        'instituciones_id',
        'Estado',
        'Visibilidad',
        'Categoria',
        'ParaI',
        'Edades',
    ];
    //
}
