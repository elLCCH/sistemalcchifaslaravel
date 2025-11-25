<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'title',
        'content',
        'order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener la sección del artículo
     */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Obtener los archivos adjuntos del artículo
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
