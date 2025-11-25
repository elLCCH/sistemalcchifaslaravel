<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Section extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'title',
        'order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener el proyecto de la sección
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Obtener los artículos de la sección
     */
    public function articles()
    {
        return $this->hasMany(Article::class);
    }
}
