<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class materias extends Model
{
    use HasApiTokens;
    protected $table = 'materias';
    // Lista de atributos asignables
    protected $fillable = [
        'plandeestudios_id',
        'Paralelo',
        'EstadoHabilitacion',
        'EstadoEnvio',
    ];
    //
}
