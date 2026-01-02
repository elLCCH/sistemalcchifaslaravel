<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Planteldocentesmaterias extends Model
{
    use HasApiTokens;
    protected $table = 'planteldocentesmaterias';
    // Lista de atributos asignables
    protected $fillable = [
        'planteldocentes_id',
        'materias_id',
    ];
    //
}
