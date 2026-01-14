<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Califhistorias extends Model
{
    //
    
    use HasApiTokens;
    protected $table = 'califhistorias';
    public $timestamps = false;
    // Lista de atributos asignables
    protected $fillable = [
        'Institucion',
        'Anio',
        'Malla',
        'Arrastre',
        'DocenteMateria',
        'NivelCurso',
        'NombreCurso',
        'Sigla',
        'Rango',
        'Tipo',
        'Horas',
        'Categoria',
        'Docente_Especialidad',
        'Docente_Practica',
        'Teorica1',
        'Teorica2',
        'Teorica3',
        'Teorica4',
        'Practica1',
        'Practica2',
        'Practica3',
        'Practica4',
        'PromEvT',
        'PromEvP',
        'Promedio',
        'PruebaRecuperacion',
        'Ap_Paterno',
        'Ap_Materno',
        'Nombre',
        'Sexo',
        'CI',
        'Especialidad',
        'Observacion',
    ];
}
