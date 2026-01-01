<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermisoAsistencia extends Model
{
    protected $table = 'permisos_asistencia';

    protected $fillable = [
        'instituciones_id',
        'infoestudiantesifas_id',
        'fecha_inicio',
        'fecha_fin',
        'aulas_virtuales_id',
        'motivo',
        'registrado_por',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];
}
