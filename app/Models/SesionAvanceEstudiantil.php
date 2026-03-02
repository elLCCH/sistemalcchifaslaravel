<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionAvanceEstudiantil extends Model
{
    protected $table = 'sesiones_avance_estudiantil';

    protected $fillable = [
        'infoestudiantesifas_id',
        'planteldocentes_id',
        'tipo_asignacion',
        'evaluacion',
        'fecha',
        'numero_clase',
        'avance_texto',
        'estrellas',
        'sugerencia',
        'asistencia',
    ];

    protected $casts = [
        'fecha'          => 'date',
        'numero_clase'   => 'integer',
        'evaluacion'     => 'integer',
        'estrellas'      => 'integer',
        // asistencia is CHAR(1): P/A/F/L
    ];

    // ─── Relaciones ───

    public function infoEstudiante()
    {
        return $this->belongsTo(Infoestudiantesifas::class, 'infoestudiantesifas_id');
    }

    public function docente()
    {
        return $this->belongsTo(Planteldocentes::class, 'planteldocentes_id');
    }
}
