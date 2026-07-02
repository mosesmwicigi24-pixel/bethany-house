<?php

namespace App\Http\Livewire\Admin\Payments;

use App\Models\PaymentMethod;
use Livewire\Component;

class PaymentMethods extends Component
{
    // Create / Edit modal
    public bool   $showModal  = false;
    public bool   $isEditing  = false;
    public ?int   $editingId  = null;

    public string $code          = '';
    public string $name          = '';
    public string $description   = '';
    public string $provider      = '';
    public bool   $isActive      = true;
    public string $currencies    = ''; // comma-separated
    public int    $sortOrder     = 0;

    // Delete confirmation
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    public function openCreate(): void
    {
        $this->reset(['code','name','description','provider','currencies','sortOrder','editingId']);
        $this->isActive  = true;
        $this->sortOrder = PaymentMethod::max('sort_order') + 1;
        $this->isEditing = false;
        $this->showModal = true;
        $this->resetErrorBag();
    }

    public function openEdit(int $id): void
    {
        $pm = PaymentMethod::findOrFail($id);
        $this->editingId   = $id;
        $this->isEditing   = true;
        $this->code        = $pm->code;
        $this->name        = $pm->name;
        $this->description = $pm->description ?? '';
        $this->provider    = $pm->provider ?? '';
        $this->isActive    = $pm->is_active;
        $this->currencies  = $pm->supported_currencies ? implode(',', $pm->supported_currencies) : '';
        $this->sortOrder   = $pm->sort_order;
        $this->showModal   = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $unique = $this->isEditing ? ','. $this->editingId : '';
        $this->validate([
            'code'      => "required|string|max:50|unique:payment_methods,code{$unique}",
            'name'      => 'required|string|max:100',
            'provider'  => 'nullable|string|max:50',
            'sortOrder' => 'integer|min:0',
        ]);

        $currencies = array_filter(array_map('trim', explode(',', $this->currencies)));

        $data = [
            'code'                 => strtolower(str_replace(' ', '_', $this->code)),
            'name'                 => $this->name,
            'description'          => $this->description ?: null,
            'provider'             => $this->provider ?: null,
            'is_active'            => $this->isActive,
            'supported_currencies' => !empty($currencies) ? array_values($currencies) : null,
            'sort_order'           => $this->sortOrder,
        ];

        if ($this->isEditing) {
            PaymentMethod::findOrFail($this->editingId)->update($data);
            $msg = 'Payment method updated.';
        } else {
            PaymentMethod::create($data);
            $msg = 'Payment method created.';
        }

        $this->showModal = false;
        session()->flash('success', $msg);
    }

    public function toggleActive(int $id): void
    {
        $pm = PaymentMethod::findOrFail($id);
        $pm->update(['is_active' => !$pm->is_active]);
    }

    public function moveUp(int $id): void
    {
        $pm  = PaymentMethod::findOrFail($id);
        $prev= PaymentMethod::where('sort_order', '<', $pm->sort_order)->orderByDesc('sort_order')->first();
        if ($prev) {
            [$pm->sort_order, $prev->sort_order] = [$prev->sort_order, $pm->sort_order];
            $pm->save(); $prev->save();
        }
    }

    public function moveDown(int $id): void
    {
        $pm   = PaymentMethod::findOrFail($id);
        $next = PaymentMethod::where('sort_order', '>', $pm->sort_order)->orderBy('sort_order')->first();
        if ($next) {
            [$pm->sort_order, $next->sort_order] = [$next->sort_order, $pm->sort_order];
            $pm->save(); $next->save();
        }
    }

    public function confirmDelete(int $id): void
    {
        $pm = PaymentMethod::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $pm->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        PaymentMethod::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} deleted.");
    }

    public function render()
    {
        $methods = PaymentMethod::ordered()->get();

        return view('livewire.admin.payments.payment-methods', [
            'methods' => $methods,
        ])->layout('layouts.admin');
    }
}