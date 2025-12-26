<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptureSession extends Model
{
    protected $table = 'capture_sessions';

    protected $fillable = [
        'token',
        'institucion_id',
        'user_id',
        'estudianteifas_id',
        'status',
        'file_path',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
