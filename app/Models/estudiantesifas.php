<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Estudiantesifas extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'estudiantesifas';

    protected $hidden = [
        'google2fa_secret',
    ];

    protected $casts = [
        'google2fa_enabled' => 'boolean',
        'google2fa_confirmed_at' => 'datetime',
    ];
    // Lista de atributos asignables
    protected $fillable = [
        'Foto',
        'Ap_Paterno',
        'Ap_Materno',
        'Nombre',
        'Sexo',
        'FechaNac',
        'Edad',
        'CI',
        'Expedido',
        'Celular',
        'Direccion',
        'Correo',
        'Nombre_Padre',
        'Nombre_Madre',
        'OcupacionP',
        'OcupacionM',
        'NumCelP',
        'NumCelM',
        'NColegio',
        'TipoColegio',
        'CGrado',
        'CNivel',
        'Usuario',
        'Contrasenia',
        'google2fa_secret',
        'google2fa_enabled',
        'google2fa_confirmed_at',
        'Estado',
        'Matricula',
        'InformacionCompartidaIFAS',
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
