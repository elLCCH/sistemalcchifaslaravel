<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Horarios_estudiantes extends Model
{
    protected $table = 'horarios_estudiantes';

    protected $fillable = [
        'infoestudiantesifas_id',
        'tipo_asignacion',
        'dia_semana',
        'hora_inicio',
        'hora_fin'
    ];
}
