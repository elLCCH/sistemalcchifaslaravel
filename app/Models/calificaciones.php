<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
class Calificaciones extends Model
{
    use HasApiTokens;
    protected $table = 'calificaciones';
    // Lista de atributos asignables
    protected $fillable = [
        'infoestudiantesifas_id',
        'materias_id',
        'Teorico1',
        'Teorico2',
        'Teorico3',
        'Teorico4',
        'Practico1',
        'Practico2',
        'Practico3',
        'Practico4',
        'PromTeorico',
        'PromPractico',
        'Promedio',
        'PruebaRecuperacion',
        'EstadoRegistroMateria',
    ];
}
