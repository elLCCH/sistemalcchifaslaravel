<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tarea extends Model
{
    protected $table = 'tareas';

    protected $fillable = [
        'publicaciones_aula_id',
        'fecha_inicio',
        'fecha_entrega',
        'fecha_cierre',
        'permitir_entrega_tardia',
        'limite_tardia_horas',
        'bloquear_recepcion',

        'puntaje_maximo',
        'tipo_calificacion',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_entrega' => 'datetime',
        'fecha_cierre' => 'datetime',
        'permitir_entrega_tardia' => 'boolean',
    ];

    public function publicacion()
    {
        return $this->belongsTo(PublicacionAula::class, 'publicaciones_aula_id');
    }

    public function entregas()
    {
        return $this->hasMany(EntregaTarea::class, 'tareas_id');
    }

    public function vinculoRubro()
    {
        return $this->hasOne(VinculoTareaRubro::class, 'tareas_id');
    }
}
