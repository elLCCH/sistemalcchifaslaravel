<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory; //PODRIA SER
use Laravel\Sanctum\HasApiTokens;

class Instituciones extends Model
{
    use HasApiTokens;
    protected $table = 'instituciones';
    // Lista de atributos asignables
    protected $fillable = [
        'AnioIncorporacion',
        'NIT',
        'Nombre',
        'Logo',
        'BannerInicial',
        'ImagenVision',
        'ImagenMision',
        'Direccion',
        'UbicacionGps',
        'Telefono',
        'Celular',
        'Celular2',
        'Celular3',
        'Mision',
        'Vision',
        'Facebook',
        'Tiktok',
        'Instagram',
        'PlataformaEducativa',
        'Historia',
        'Funciones',
        'Caractisticas',
        'Estado',
        'Visibilidad',
    ];
}
