<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Jurado extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'jurados';

    protected $hidden = [
        'password',
    ];

    protected $fillable = [
        'nombres',
        'apellidos',
        'carnet',
        'usuario',
        'password',
        'foto',
        'biografia',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asignaciones()
    {
        return $this->hasMany(JuradoAsignacion::class, 'jurado_id');
    }

    public function calificaciones()
    {
        return $this->hasMany(CalificacionesEvento::class, 'jurado_id');
    }

    public function createPersonalizedToken($tokenName, $abilities, $expiration, $additionalInfo = [])
    {
        $token = $this->createToken($tokenName, $abilities, $expiration);
        $token->accessToken->forceFill($additionalInfo)->save();
        return $token;
    }
}
