<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;
class estudiantesifas extends Model
{
    use HasApiTokens;
    protected $table = 'estudiantesifas';
    // Lista de atributos asignables
    protected $fillable = [
        'Foto',
        'Ap_Paterno',
        'Ap_Materno',
        'Nombre',
        'Sexo',
        'FechaNac',
        'Edad',
        'CI',
        'Expedido',
        'Celular',
        'Direccion',
        'Correo',
        'Nombre_Padre',
        'Nombre_Madre',
        'OcupacionP',
        'OcupacionM',
        'NumCelP',
        'NumCelM',
        'NColegio',
        'TipoColegio',
        'CGrado',
        'CNivel',
        'Usuario',
        'Contrasenia',
        'Estado',
        'Matricula',
        'InstrumentoMusical',
        'IntrumentoMusicalSecundario',
        'InformacionCompartidaIFAS',
    ];
    //
}
