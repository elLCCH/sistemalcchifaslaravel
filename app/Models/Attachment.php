<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'file_name',
        'stored_name',
        'size',
        'content_type',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener el artículo del archivo
     */
    public function article()
    {
        return $this->belongsTo(Article::class);
    }
}
