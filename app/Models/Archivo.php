<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Archivo extends Model
{
    protected $table = 'archivos';

    protected $fillable = [
        'instituciones_id',
        'nombre_original',
        'nombre_almacenado',
        'ruta',
        'tamano',
        'tipo_mime',
        'subido_por_tipo',
        'subido_por_id',
        'estado',
        'visibilidad',
    ];

    public function relaciones()
    {
        return $this->hasMany(ArchivoRelacion::class, 'archivos_id');
    }
}
