<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsistenciaRegistro extends Model
{
    protected $table = 'asistencias_registros';

    protected $fillable = [
        'asistencias_sesiones_id',
        'infoestudiantesifas_id',
        'estado_asistencia',
        'metodo',
        'fecha_registro',
        'asistencias_qr_tokens_id',
        'gps_lat',
        'gps_lng',
        'gps_precision_m',
        'gps_distancia_m',
        'gps_valido',
        'observacion',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'gps_valido' => 'boolean',
        'fecha_registro' => 'datetime',
    ];
}
