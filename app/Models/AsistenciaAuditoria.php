<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsistenciaAuditoria extends Model
{
    protected $table = 'asistencias_auditoria';

    public $timestamps = false;

    protected $fillable = [
        'asistencias_registros_id',
        'accion',
        'antes',
        'despues',
        'actor_tipo',
        'actor_id',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'datetime',
    ];
}
