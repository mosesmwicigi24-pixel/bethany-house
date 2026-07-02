<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'language_code',
        'name',
        'description',
        'short_description',
        'specifications',
    ];

    protected $casts = [
        'specifications' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
