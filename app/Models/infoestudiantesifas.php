<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;
class Infoestudiantesifas extends Model
{
    use HasApiTokens;
    protected $table = 'infoestudiantesifas';
    // Lista de atributos asignables
    protected $fillable = [
        'estudiantesifas_id',
        'planteldocadmins_id',
        'planteldocadmins_idPC',
        'planteldocadmins_idOtros',
        'instituciones_id',
        'FechInsc',
        'Verificacion',
        'Anotaciones',
        'Notas',
        'Observacion',
        'Categoria',
        'Turno',
        'Curso_Solicitado',
        'Paralelo_Solicitado',
        'CantidadMateriasAsignadas',
        'InstrumentoMusical',
        'InstrumentoMusicalSecundario',
    ];
    //
}
