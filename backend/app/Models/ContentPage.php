<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'page_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function translations()
    {
        return $this->hasMany(ContentPageTranslation::class, 'page_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTranslation($languageCode = 'en')
    {
        return $this->translations()->where('language_code', $languageCode)->first();
    }
}
