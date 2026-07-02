<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_id',
        'material_id',
        'quantity',
        'unit_of_measure',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function billOfMaterial()
    {
        return $this->belongsTo(BillOfMaterial::class, 'bom_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
