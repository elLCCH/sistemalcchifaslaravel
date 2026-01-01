<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicacionAula extends Model
{
    protected $table = 'publicaciones_aula';

    protected $fillable = [
        'aulas_virtuales_id',
        'tipo',
        'titulo',
        'descripcion',
        'creado_por_tipo',
        'creado_por_id',
        'fecha_publicacion',
        'estado',
        'visibilidad',
    ];

    protected $casts = [
        'fecha_publicacion' => 'datetime',
    ];

    public function aula()
    {
        return $this->belongsTo(AulaVirtual::class, 'aulas_virtuales_id');
    }

    public function tarea()
    {
        return $this->hasOne(Tarea::class, 'publicaciones_aula_id');
    }
}
