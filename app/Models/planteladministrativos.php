<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Foundation\Auth\User as Authenticatable;
class planteladministrativos extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'planteladministrativos';
    // Lista de atributos asignables
    protected $fillable = [
        'instituciones_id',
        'Nombres',
        'Apellidos',
        'Sexo',
        'FechaNac',
        'Usuario',
        'Contrasenia',
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
