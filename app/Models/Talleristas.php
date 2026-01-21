<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Talleristas extends Model
{
    protected $table = 'talleristas';

    protected $fillable = [
        'instituciones_id',
        'Foto',
        'Ap_Paterno',
        'Ap_Materno',
        'Nombre',
        'Carnet',
        'Celular',
        'Nombre_Padre',
        'Celular_Padre',
        'Nombre_Madre',
        'Celular_Madre',
    ];
}