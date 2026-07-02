<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentPageTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'language_code',
        'title',
        'content',
        'meta_title',
        'meta_description',
    ];

    public function page()
    {
        return $this->belongsTo(ContentPage::class, 'page_id');
    }
}
