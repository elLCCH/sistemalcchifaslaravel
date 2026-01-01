<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalificacionTarea extends Model
{
    protected $table = 'calificaciones_tareas';

    protected $fillable = [
        'entregas_tareas_id',
        'planteldocentes_id',
        'puntaje_obtenido',
        'comentario_docente',
        'fecha_calificacion',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'fecha_calificacion' => 'datetime',
    ];

    public function entrega()
    {
        return $this->belongsTo(EntregaTarea::class, 'entregas_tareas_id');
    }
}
