<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LugarDesierto extends Model
{
    protected $table = 'lugaresdesiertos';

    protected $fillable = ['evento_id', 'especialidad', 'categoria', 'nroinstancia', 'lugar'];
}
