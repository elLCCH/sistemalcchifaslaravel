<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asignaciones_lote_items extends Model
{
    protected $table = 'asignaciones_lote_items';

    protected $fillable = [
        'lote_uuid',
        'infoestudiantesifas_id',
        'calificaciones_id',
        'materias_id',
        'action',
        'prev_notas',
        'prev_verificacion',
        'new_notas',
        'new_verificacion',
        'created_at',
    ];

    protected $casts = [
        'infoestudiantesifas_id' => 'integer',
        'calificaciones_id' => 'integer',
        'materias_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    public function lote()
    {
        return $this->belongsTo(Asignaciones_lotes::class, 'lote_uuid', 'uuid');
    }
}
