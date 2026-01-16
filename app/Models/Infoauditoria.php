<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Infoauditoria extends Model
{
    protected $table = 'infoauditorias';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_nombrecompleto',
        'actor_pertenencia',
        'actor_permisos',

        'accion',
        'recurso',
        'recurso_id',

        'metodo',
        'url',
        'route_name',
        'ip',
        'user_agent',

        'request_headers',
        'request_body',
        'old_values',
        'new_values',

        'status_code',
        'mensaje',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
