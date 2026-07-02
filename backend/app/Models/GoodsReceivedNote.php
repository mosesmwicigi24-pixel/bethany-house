<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceivedNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'grn_number',
        'purchase_order_id',
        'outlet_id',
        'received_date',
        'invoice_number',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'received_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($grn) {
            if (empty($grn->grn_number)) {
                $grn->grn_number = 'GRN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(GrnItem::class, 'grn_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
