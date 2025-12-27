<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Pagoslcch extends Model
{
    use HasApiTokens;

    protected $table = 'pagoslcch';

    protected $fillable = [
        'infoestudiantesifas_id',
        'mes',
        'gestion',
        'monto',
        'fechapago',
        'horapago',
        'file',
        'observacion',
        'estadopago',
    ];
}
