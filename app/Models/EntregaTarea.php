<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntregaTarea extends Model
{
    protected $table = 'entregas_tareas';

    protected $fillable = [
        'tareas_id',
        'infoestudiantesifas_id',
        'estado',
        'fecha_entrega',
        'comentario_estudiante',
        'numero_reentrega',
    ];

    protected $casts = [
        'fecha_entrega' => 'datetime',
    ];

    public function tarea()
    {
        return $this->belongsTo(Tarea::class, 'tareas_id');
    }

    public function calificacion()
    {
        return $this->hasOne(CalificacionTarea::class, 'entregas_tareas_id');
    }
}
