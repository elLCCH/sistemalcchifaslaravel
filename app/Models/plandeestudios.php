<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Plandeestudios extends Model
{
    use HasApiTokens;
    protected $table = 'plandeestudios';
    // Lista de atributos asignables
    protected $fillable = [
        'carreras_id',
        'Rango',
        'RangoLvlCurso',
        'LvlCurso',
        'Horas',
        'anio_id',
        'ModoMateria',
        'NombreMateria',
        'SiglaMateria',
        'Prerrequisitos',
        'SiglasPrerrequisitos',
        'TipoMateria',
        'Periodo',
        'RelacionDocenteCursoAEstudiante',
    ];
    //
}
