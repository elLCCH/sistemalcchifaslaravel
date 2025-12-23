<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory; //PODRIA SER
use Laravel\Sanctum\HasApiTokens;
class anios extends Model
{
    // use HasFactory;

    //USAR SOLO ESTO SI HAY NECESIDAD DE SER MAS TECNICO
    
    use HasApiTokens;
    protected $table = 'anios';
    // Lista de atributos asignables
    protected $fillable = [
        'Anio',
        'Estado',
        'Visibilidad',
        'Rector',
        'DirAcademico',
        'Predeterminado',
    ];
}
