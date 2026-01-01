<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsistenciaSesion extends Model
{
    protected $table = 'asistencias_sesiones';

    protected $fillable = [
        'instituciones_id',
        'aulas_virtuales_id',
        'planteldocentes_id',
        'fecha',
        'hora_ingreso',
        'tiempo_espera_minutos',
        'minutos_falta',
        'gps_requerido',
        'radio_metros',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'gps_requerido' => 'boolean',
        'hora_ingreso' => 'datetime',
        'fecha' => 'date',
    ];
}
