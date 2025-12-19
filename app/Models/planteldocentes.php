<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class planteldocentes extends Model
{
    use HasApiTokens;
    protected $table = 'planteldocentes';
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
}
