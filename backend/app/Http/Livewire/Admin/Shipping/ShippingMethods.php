<?php

namespace App\Http\Livewire\Admin\Shipping;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Livewire\Component;

class ShippingMethods extends Component
{
    // ── Filters ────────────────────────────────────────────────────────────────
    public string $zoneFilter   = '';
    public string $statusFilter = '';

    // ── Form modal ─────────────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;

    public string $name             = '';
    public string $description      = '';
    public string $shippingZoneId   = '';
    public string $deliveryTime     = '';
    public string $costType         = 'flat_rate';
    public string $flatRate         = '';
    public string $minOrderAmount   = '';
    public bool   $isActive         = true;
    public int    $sortOrder        = 0;

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    public function openCreate(): void
    {
        $this->resetForm();
        $this->sortOrder = ShippingMethod::max('sort_order') + 1;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $m = ShippingMethod::findOrFail($id);
        $this->editingId      = $id;
        $this->isEditing      = true;
        $this->name           = $m->name;
        $this->description    = $m->description ?? '';
        $this->shippingZoneId = (string) ($m->shipping_zone_id ?? '');
        $this->deliveryTime   = $m->delivery_time ?? '';
        $this->costType       = $m->cost_type;
        $this->flatRate       = $m->flat_rate ? (string) $m->flat_rate : '';
        $this->minOrderAmount = $m->min_order_amount ? (string) $m->min_order_amount : '';
        $this->isActive       = $m->is_active;
        $this->sortOrder      = $m->sort_order;
        $this->showModal      = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate([
            'name'           => 'required|string|max:150',
            'shippingZoneId' => 'required|exists:shipping_zones,id',
            'costType'       => 'required|in:flat_rate,free,percentage,weight_based',
            'flatRate'       => 'nullable|numeric|min:0',
            'minOrderAmount' => 'nullable|numeric|min:0',
            'sortOrder'      => 'integer|min:0',
        ]);

        $data = [
            'name'              => $this->name,
            'description'       => $this->description ?: null,
            'shipping_zone_id'  => $this->shippingZoneId,
            'delivery_time'     => $this->deliveryTime ?: null,
            'cost_type'         => $this->costType,
            'flat_rate'         => $this->flatRate ?: null,
            'min_order_amount'  => $this->minOrderAmount ?: null,
            'is_active'         => $this->isActive,
            'sort_order'        => $this->sortOrder,
        ];

        if ($this->isEditing) {
            ShippingMethod::findOrFail($this->editingId)->update($data);
            $msg = 'Shipping method updated.';
        } else {
            ShippingMethod::create($data);
            $msg = 'Shipping method created.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    public function toggleActive(int $id): void
    {
        $m = ShippingMethod::findOrFail($id);
        $m->update(['is_active' => !$m->is_active]);
    }

    public function moveUp(int $id): void
    {
        $m    = ShippingMethod::findOrFail($id);
        $prev = ShippingMethod::where('sort_order', '<', $m->sort_order)
            ->where('shipping_zone_id', $m->shipping_zone_id)
            ->orderByDesc('sort_order')->first();
        if ($prev) {
            [$m->sort_order, $prev->sort_order] = [$prev->sort_order, $m->sort_order];
            $m->save(); $prev->save();
        }
    }

    public function moveDown(int $id): void
    {
        $m    = ShippingMethod::findOrFail($id);
        $next = ShippingMethod::where('sort_order', '>', $m->sort_order)
            ->where('shipping_zone_id', $m->shipping_zone_id)
            ->orderBy('sort_order')->first();
        if ($next) {
            [$m->sort_order, $next->sort_order] = [$next->sort_order, $m->sort_order];
            $m->save(); $next->save();
        }
    }

    public function confirmDelete(int $id): void
    {
        $m = ShippingMethod::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $m->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        ShippingMethod::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} deleted.");
    }

    protected function resetForm(): void
    {
        $this->reset(['name','description','shippingZoneId','deliveryTime','flatRate','minOrderAmount','editingId']);
        $this->costType  = 'flat_rate';
        $this->isActive  = true;
        $this->sortOrder = 0;
        $this->isEditing = false;
        $this->resetErrorBag();
    }

    public function getSummaryProperty(): array
    {
        return ShippingMethod::selectRaw("
            COUNT(*)                                       AS total,
            COUNT(*) FILTER (WHERE is_active = true)      AS active,
            COUNT(*) FILTER (WHERE cost_type = 'free')    AS free_shipping,
            COUNT(*) FILTER (WHERE is_active = false)     AS inactive
        ")->first()->toArray();
    }

    public function render()
    {
        $methods = ShippingMethod::with('shippingZone')
            ->when($this->zoneFilter,   fn($q) => $q->where('shipping_zone_id', $this->zoneFilter))
            ->when($this->statusFilter === 'active',   fn($q) => $q->active())
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('shipping_zone_id')
            ->ordered()
            ->get()
            ->groupBy('shipping_zone_id');

        return view('livewire.admin.shipping.shipping-methods', [
            'methods'  => $methods,
            'zones'    => ShippingZone::orderBy('name')->get(),
            'summary'  => $this->summary,
            'costTypes'=> ['flat_rate','free','percentage','weight_based'],
        ])->layout('layouts.admin');
    }
}