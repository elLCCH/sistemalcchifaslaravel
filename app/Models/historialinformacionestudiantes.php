<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;
class historialinformacionestudiantes extends Model
{
    use HasApiTokens;
    protected $table = 'historialinformacionestudiantes';
    // Lista de atributos asignables
    protected $fillable = [
        'Nombres',
        'Apellidos',
        'Anio',
        'Categoria',
        'DocenteEspecialidad',
        'DocentePC',
        'DocenteOtros',
        'Institucion',
        'Especialidad',
        'Observacion',
        'Turno',
        'Edad',
        'MallaCurricular',
    ];
    //
}
