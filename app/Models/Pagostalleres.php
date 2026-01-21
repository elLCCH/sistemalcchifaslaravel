<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pagostalleres extends Model
{
    protected $table = 'pagostalleres';

    protected $fillable = [
        'instituciones_id',
        'talleristas_id',
        'planteldocentes_id',
        'Especialidad',
        'Turno',
        'Horario',
        'FechaPago',
        'FechaHasta',
        'MontoPagado',
        'DetallePago',
        'Observacion',
        'ComprobantePago',
    ];
}