<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Eventos extends Model
{
    protected $table = 'eventos';

    protected $fillable = [
        'instituciones_id',
        'Anio',
        'NombreEvento',
        'Descripcion',
        'Lugar',
        'FechaInicio',
        'FechaFin',
        'ModoInscripcion',
        'PublicoWeb',
        'Activo',
        'Requisitos',
        'Parametros',
        'InputsEspecial',
        'TienePago',
        'Monto',
        'Moneda',
        'diseniocertificadopdfs_id',
        'CertificadoConfig',
        'Observacion',
    ];
}
