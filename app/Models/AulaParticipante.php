<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AulaParticipante extends Model
{
    protected $table = 'aulas_participantes';

    protected $fillable = [
        'aulas_virtuales_id',
        'tipo',
        'infoestudiantesifas_id',
        'planteldocentes_id',
        'planteladministrativos_id',
        'rol',
        'puede_publicar',
        'puede_calificar',
        'puede_administrar',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'puede_publicar' => 'boolean',
        'puede_calificar' => 'boolean',
        'puede_administrar' => 'boolean',
    ];

    public function aula()
    {
        return $this->belongsTo(AulaVirtual::class, 'aulas_virtuales_id');
    }
}
