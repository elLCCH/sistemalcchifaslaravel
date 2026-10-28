<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalificacionesEvento extends Model
{
    protected $table = 'calificacioneseventos';

    protected $fillable = [
        'jurado_id',
        'estudiantesevento_id',
        'rubrica_id',
        'puntaje_asignado',
        'observaciones',
    ];

    protected $casts = [
        'puntaje_asignado' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function jurado()
    {
        return $this->belongsTo(Jurado::class, 'jurado_id');
    }

    public function estudianteEvento()
    {
        return $this->belongsTo(Estudianteseventos::class, 'estudiantesevento_id');
    }

    public function rubrica()
    {
        return $this->belongsTo(RubricasEvento::class, 'rubrica_id');
    }
}
