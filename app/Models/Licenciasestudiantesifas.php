<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Licenciasestudiantesifas extends Model
{
    protected $table = 'licenciasestudiantesifas';

    protected $fillable = [
        'instituciones_id',
        'anios_id',
        'infoestudiantesifas_id',
        'fecha_inicio',
        'fecha_fin',
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
