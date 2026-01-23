<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Foundation\Auth\User as Authenticatable;
class Planteldocentes extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'planteldocentes';

    protected $hidden = [
        'google2fa_secret',
    ];

    protected $casts = [
        'google2fa_enabled' => 'boolean',
        'google2fa_confirmed_at' => 'datetime',
    ];
    // Lista de atributos asignables
    protected $fillable = [
        'instituciones_id',
        'Nombres',
        'Apellidos',
        'Sexo',
        'FechaNac',
        'Usuario',
        'Contrasenia',
        'google2fa_secret',
        'google2fa_enabled',
        'google2fa_confirmed_at',
        'Celular',
        'CelularTrabajo',
        'Carnet',
        'Foto',
        'Estado',
        'Tipo',
        'Permisos',
        'Cargo',
        'Biografia',
        'Visibilidad',
    ];
    //
    
    public function createPersonalizedToken($tokenName, $abilities, $expiration, $additionalInfo = [])
    {
        $token = $this->createToken($tokenName, $abilities,$expiration);

        // Agregar información adicional al token
        $token->accessToken->forceFill($additionalInfo)->save();

        return $token;
    }
}
