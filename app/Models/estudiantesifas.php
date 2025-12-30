<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
class estudiantesifas extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'estudiantesifas';
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
