<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminarClaseLog extends Model
{
    protected $table = 'terminar_clase_logs';

    protected $fillable = [
        'planteldocentes_id',
        'instituciones_id',
        'tipo_asignacion',
        'evaluacion',
        'fecha',
        'cursos_json',
        'sesiones_creadas_ids',
        'cantidad_creadas',
        'deshecho_at',
        'deshecho_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'evaluacion' => 'integer',
        'cantidad_creadas' => 'integer',
        'deshecho_at' => 'datetime',
    ];
}
