<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class carreras extends Model
{
    use HasApiTokens;
    protected $table = 'carreras';
    // Lista de atributos asignables
    protected $fillable = [
        'instituciones_id',
        'NombreCarrera',
        'Descripcion',
        'Area',
        'Mencion',
        'Resolucion',
        'Programa',
        'CantidadEvaluaciones',
        'Nivel',
        'Capacitacion',
        'CarreraProfesional',
        'Modalidad',
        'Duracion',
        'HorasTotales',
        'TituloOficial',
        'Estado',
        'Visibilidad',
    ];
}
