<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asignaciones_lotes extends Model
{
    protected $table = 'asignaciones_lotes';

    protected $fillable = [
        'uuid',
        'instituciones_id',
        'anio_id',
        'resolucion',
        'nivel',
        'cursos_json',
        'actor_type',
        'actor_id',
        'created_at',
        'rolled_back_at',
        'rolled_back_by_type',
        'rolled_back_by_id',
    ];

    protected $casts = [
        'instituciones_id' => 'integer',
        'anio_id' => 'integer',
        'actor_id' => 'integer',
        'rolled_back_by_id' => 'integer',
        'cursos_json' => 'array',
        'created_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    public function items()
    {
        return $this->hasMany(Asignaciones_lote_items::class, 'lote_uuid', 'uuid');
    }
}
