<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AulaVirtual extends Model
{
    protected $table = 'aulas_virtuales';

    protected $fillable = [
        'instituciones_id',
        'materias_id',
        'nombre',
        'descripcion',
        'estado',
        'visibilidad',
    ];

    public function participantes()
    {
        return $this->hasMany(AulaParticipante::class, 'aulas_virtuales_id');
    }

    public function publicaciones()
    {
        return $this->hasMany(PublicacionAula::class, 'aulas_virtuales_id');
    }
}
