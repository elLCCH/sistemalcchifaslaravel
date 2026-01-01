<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivoRelacion extends Model
{
    public $timestamps = false;

    protected $table = 'archivos_relaciones';

    protected $fillable = [
        'archivos_id',
        'relacion_tipo',
        'relacion_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function archivo()
    {
        return $this->belongsTo(Archivo::class, 'archivos_id');
    }
}
