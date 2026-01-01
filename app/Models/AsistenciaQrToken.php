<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsistenciaQrToken extends Model
{
    protected $table = 'asistencias_qr_tokens';

    public $timestamps = false;

    protected $fillable = [
        'asistencias_sesiones_id',
        'token',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
