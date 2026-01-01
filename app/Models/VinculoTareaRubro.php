<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VinculoTareaRubro extends Model
{
    protected $table = 'vinculos_tarea_rubro';

    protected $fillable = [
        'tareas_id',
        'rubros_evaluacion_id',
        'modo',
    ];

    public function tarea()
    {
        return $this->belongsTo(Tarea::class, 'tareas_id');
    }
}
