<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;

class usuarioslcchs extends Authenticatable
{
     use HasApiTokens, HasFactory;
    protected $table = 'usuarioslcchs';
    // Lista de atributos asignables
    protected $fillable = [
        'Nombres',
        'Apellidos',
        'Usuario',
        'Contrasenia',
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
