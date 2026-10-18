<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One currency's drawer inside a till (currency plan Step 2, owner 2026-10-18).
 *
 * A till holds one row per currency it has touched. The HOME row (the till's
 * own currency_code) mirrors the register's money columns, which stay the
 * figure every older reader sees; a foreign row stands alone: its own float,
 * its own expected cash, its own count and variance. Nothing is added across
 * rows. Written only through App\Services\Tills\TillDrawer.
 *
 * Locked with the till: once the register is finalized, the model refuses a
 * change and on Postgres a trigger refuses it underneath.
 */
class CashRegisterBalance extends Model
{
    protected $fillable = [
        'cash_register_id', 'currency_code', 'is_home', 'opening_balance', 'expected_cash', 'actual_cash',
        'expected_cash_at_count', 'expected_cash_running_at_count', 'variance', 'variance_class',
        'denomination_count', 'total_sales', 'total_cash_sales', 'total_card_sales', 'total_mpesa_sales',
        'total_refunds', 'transaction_count',
    ];

    protected $casts = [
        'is_home'                        => 'boolean',
        'opening_balance'                => 'decimal:2',
        'expected_cash'                  => 'decimal:2',
        'actual_cash'                    => 'decimal:2',
        'expected_cash_at_count'         => 'decimal:2',
        'expected_cash_running_at_count' => 'decimal:2',
        'variance'                       => 'decimal:2',
        'denomination_count'             => 'array',
        'total_sales'                    => 'decimal:2',
        'total_cash_sales'               => 'decimal:2',
        'total_card_sales'               => 'decimal:2',
        'total_mpesa_sales'              => 'decimal:2',
        'total_refunds'                  => 'decimal:2',
        'transaction_count'              => 'integer',
    ];

    protected static function booted(): void
    {
        $locked = function (self $b) {
            $finalized = CashRegister::whereKey($b->cash_register_id)->value('finalized_at');
            if ($finalized !== null) {
                throw new \App\Exceptions\TillFinalizedException();
            }
        };
        static::updating($locked);
        static::deleting($locked);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }
}
