<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RubricasEvento extends Model
{
    protected $table = 'rubricaseventos';

    protected $fillable = [
        'evento_id',
        'especialidad',
        'categoria',
        'criterio',
        'puntaje_maximo',
    ];

    protected $casts = [
        'puntaje_maximo' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function evento()
    {
        return $this->belongsTo(Eventos::class, 'evento_id');
    }

    public function calificaciones()
    {
        return $this->hasMany(CalificacionesEvento::class, 'rubrica_id');
    }
}
