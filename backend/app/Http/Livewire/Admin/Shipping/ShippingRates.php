<?php

namespace App\Http\Livewire\Admin\Shipping;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Livewire\Component;

class ShippingRates extends Component
{
    public string $zoneFilter = '';

    // ── Inline edit ────────────────────────────────────────────────────────────
    // Stores [method_id => ['flat_rate' => ..., 'min_order_amount' => ...]]
    public array  $pendingEdits = [];
    public ?int   $editingMethodId = null;

    // ── Bulk rate adjust modal ─────────────────────────────────────────────────
    public bool   $showBulkModal  = false;
    public string $bulkZoneId     = '';
    public string $bulkAdjustType = 'fixed'; // fixed | percentage
    public string $bulkValue      = '';

    public function startEdit(int $methodId): void
    {
        $m = ShippingMethod::findOrFail($methodId);
        $this->editingMethodId = $methodId;
        $this->pendingEdits[$methodId] = [
            'flat_rate'         => $m->flat_rate ? (string) $m->flat_rate : '',
            'min_order_amount'  => $m->min_order_amount ? (string) $m->min_order_amount : '',
            'cost_type'         => $m->cost_type,
        ];
    }

    public function cancelEdit(): void
    {
        if ($this->editingMethodId) {
            unset($this->pendingEdits[$this->editingMethodId]);
        }
        $this->editingMethodId = null;
    }

    public function saveRate(int $methodId): void
    {
        $this->validate([
            "pendingEdits.{$methodId}.flat_rate"        => 'nullable|numeric|min:0',
            "pendingEdits.{$methodId}.min_order_amount" => 'nullable|numeric|min:0',
            "pendingEdits.{$methodId}.cost_type"        => 'required|in:flat_rate,free,percentage,weight_based',
        ]);

        $edit = $this->pendingEdits[$methodId];
        ShippingMethod::findOrFail($methodId)->update([
            'flat_rate'         => $edit['flat_rate'] ?: null,
            'min_order_amount'  => $edit['min_order_amount'] ?: null,
            'cost_type'         => $edit['cost_type'],
        ]);

        unset($this->pendingEdits[$methodId]);
        $this->editingMethodId = null;
        session()->flash('success', 'Rate updated.');
    }

    public function applyBulkAdjust(): void
    {
        $this->validate([
            'bulkZoneId'     => 'required|exists:shipping_zones,id',
            'bulkAdjustType' => 'required|in:fixed,percentage',
            'bulkValue'      => 'required|numeric',
        ]);

        $methods = ShippingMethod::where('shipping_zone_id', $this->bulkZoneId)
            ->where('cost_type', 'flat_rate')
            ->get();

        foreach ($methods as $method) {
            $current = (float) $method->flat_rate;
            $new     = $this->bulkAdjustType === 'percentage'
                ? $current * (1 + (float) $this->bulkValue / 100)
                : $current + (float) $this->bulkValue;
            $method->update(['flat_rate' => max(0, round($new, 2))]);
        }

        $this->showBulkModal = false;
        $this->reset(['bulkZoneId','bulkAdjustType','bulkValue']);
        session()->flash('success', "Rates updated for {$methods->count()} method(s).");
    }

    public function render()
    {
        $zones = ShippingZone::with([
            'methods' => fn($q) => $q->ordered(),
        ])
        ->when($this->zoneFilter, fn($q) => $q->where('id', $this->zoneFilter))
        ->orderBy('name')
        ->get();

        return view('livewire.admin.shipping.shipping-rates', [
            'zones' => $zones,
            'allZones' => ShippingZone::orderBy('name')->get(),
        ])->layout('layouts.admin');
    }
}