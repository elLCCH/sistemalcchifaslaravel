<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Bibliotecaarchivoslcch extends Model
{
    use HasApiTokens;

    protected $table = 'bibliotecaarchivoslcch';

    protected $fillable = [
        'institucion_id',
        'categoria',
        'nombre_documento',
        'fecha',
        'archivo',
        'estado',
        'visibilidad',
        'publicado_por',
        'dirigido',
        'descripcion',
    ];
}
