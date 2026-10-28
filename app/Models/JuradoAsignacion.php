<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JuradoAsignacion extends Model
{
    protected $table = 'jurado_asignaciones';

    protected $fillable = [
        'jurado_id',
        'evento_id',
        'especialidad',
        'categoria',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function jurado()
    {
        return $this->belongsTo(Jurado::class, 'jurado_id');
    }

    public function evento()
    {
        return $this->belongsTo(Eventos::class, 'evento_id');
    }
}
