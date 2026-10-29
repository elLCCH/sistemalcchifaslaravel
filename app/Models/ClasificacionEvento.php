<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClasificacionEvento extends Model
{
    protected $table = 'clasificacioneseventos';

    protected $fillable = ['estudiantesevento_id', 'nroinstancia'];
}
