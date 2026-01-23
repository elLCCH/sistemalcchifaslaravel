<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Usuarioslcchs extends Authenticatable
{
    //
     use HasApiTokens, HasFactory;
    protected $table = 'usuarioslcchs';

    protected $hidden = [
        'google2fa_secret',
    ];

    protected $casts = [
        'google2fa_enabled' => 'boolean',
        'google2fa_confirmed_at' => 'datetime',
    ];
    // Lista de atributos asignables
    protected $fillable = [
        'Nombres',
        'Apellidos',
        'Usuario',
        'Contrasenia',
        'google2fa_secret',
        'google2fa_enabled',
        'google2fa_confirmed_at',
        'CelularTrabajo',
        'Foto',
        'Estado',
        'Tipo',
        'Permisos',
        'Cargo',
        'Biografia',
        'Visibilidad',
    ];
    public function createPersonalizedToken($tokenName, $abilities, $expiration, $additionalInfo = [])
    {
        $token = $this->createToken($tokenName, $abilities,$expiration);

        // Agregar información adicional al token
        $token->accessToken->forceFill($additionalInfo)->save();

        return $token;
    }
}
