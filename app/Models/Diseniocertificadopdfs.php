<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diseniocertificadopdfs extends Model
{
    protected $table = 'diseniocertificadopdfs';

    protected $fillable = [
        'instituciones_id',
        'Nombre',
        'ArchivoPdf',
        'Parametros',
        'Activo',
        'Observacion',
    ];
}
