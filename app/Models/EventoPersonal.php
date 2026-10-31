<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Inscripción de un docente o administrativo a un evento (sin tocar las tablas de plantel).
class EventoPersonal extends Model
{
    protected $table = 'eventospersonal';

    protected $fillable = [
        'instituciones_id',
        'eventos_id',
        'tipo',
        'planteldocentes_id',
        'planteladministrativos_id',
        'rol',
        'parametro1',
        'parametro2',
        'parametro3',
        'parametro4',
        'parametro5',
    ];

    public function evento()
    {
        return $this->belongsTo(Eventos::class, 'eventos_id');
    }

    public function docente()
    {
        return $this->belongsTo(Planteldocentes::class, 'planteldocentes_id');
    }

    public function administrativo()
    {
        return $this->belongsTo(Planteladministrativos::class, 'planteladministrativos_id');
    }
}
