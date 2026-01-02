<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Inicios extends Model
{
    use HasApiTokens;
    protected $table = 'inicios';
    // Lista de atributos asignables
    protected $fillable = [
        'archivo',
        'etiqueta',
        'titulo',
        'subtitulo',
        'descripcion',
        'categoria',
        'link',
        'costo',
        'duracion',
        'cupos',
        'fecha',
        'icono',
        'logo',
        'estado',
        'visibilidad',
        'id_institucion',
    ];
    //
}
