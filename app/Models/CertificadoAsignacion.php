<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificadoAsignacion extends Model
{
    protected $table = 'certificadoasignaciones';

    protected $fillable = [
        'evento_id',
        'especialidad',
        'categoria',
        'diseniocertificadopdfs_id',
    ];

    public function evento()
    {
        return $this->belongsTo(Eventos::class, 'evento_id');
    }

    public function diseno()
    {
        return $this->belongsTo(Diseniocertificadopdfs::class, 'diseniocertificadopdfs_id');
    }
}
