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
        'LogoBoletin2026',
        'LogoCentralizador2026',
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
        'ColorInstitucional',
        'ColorFuerte',
        'ColorMedio',
        'ColorBajo',
        'Estado',
        'Visibilidad',
    ];

    protected $casts = [
        'LogoBoletin2026' => 'array',
        'LogoCentralizador2026' => 'array',
    ];
}
