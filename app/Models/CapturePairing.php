<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapturePairing extends Model
{
    protected $table = 'capture_pairings';

    protected $fillable = [
        'token',
        'institucion_id',
        'user_id',
        'status',
        'device_label',
        'pending_capture_token',
        'linked_at',
        'last_seen_at',
        'expires_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
