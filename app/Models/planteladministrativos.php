<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class planteladministrativos extends Model
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
}
