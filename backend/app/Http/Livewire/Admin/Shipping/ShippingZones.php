<?php

namespace App\Http\Livewire\Admin\Shipping;

use App\Models\Country;
use App\Models\ShippingZone;
use Livewire\Component;

class ShippingZones extends Component
{
    // ── Form modal ─────────────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;

    public string $name          = '';
    public string $description   = '';
    public bool   $isActive      = true;
    public array  $selectedCountries = []; // country codes

    // Country search
    public string $countrySearch = '';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool         $showDetail = false;
    public ?ShippingZone $viewing   = null;

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    public function getFilteredCountriesProperty()
    {
        return Country::shippingEnabled()
            ->when($this->countrySearch, fn($q) =>
                $q->where('name', 'ilike', "%{$this->countrySearch}%")
                  ->orWhere('code', 'ilike', "%{$this->countrySearch}%")
            )
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    public function viewZone(int $id): void
    {
        $this->viewing = ShippingZone::with(['countries', 'methods'])
            ->withCount('methods')
            ->find($id);
        $this->showDetail = true;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $z = ShippingZone::with('countries')->findOrFail($id);
        $this->editingId          = $id;
        $this->isEditing          = true;
        $this->name               = $z->name;
        $this->description        = $z->description ?? '';
        $this->isActive           = $z->is_active;
        $this->selectedCountries  = $z->countries->pluck('code')->toArray();
        $this->showModal          = true;
        $this->showDetail         = false;
        $this->resetErrorBag();
    }

    public function toggleCountry(string $code): void
    {
        if (in_array($code, $this->selectedCountries)) {
            $this->selectedCountries = array_values(array_filter(
                $this->selectedCountries, fn($c) => $c !== $code
            ));
        } else {
            $this->selectedCountries[] = $code;
        }
    }

    public function save(): void
    {
        $this->validate([
            'name'     => 'required|string|max:150',
            'isActive' => 'boolean',
        ]);

        $data = [
            'name'        => $this->name,
            'description' => $this->description ?: null,
            'is_active'   => $this->isActive,
        ];

        if ($this->isEditing) {
            $zone = ShippingZone::findOrFail($this->editingId);
            $zone->update($data);
            $zone->countries()->sync($this->selectedCountries);
            $msg = 'Shipping zone updated.';
        } else {
            $zone = ShippingZone::create($data);
            $zone->countries()->sync($this->selectedCountries);
            $msg = 'Shipping zone created.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    public function toggleActive(int $id): void
    {
        $z = ShippingZone::findOrFail($id);
        $z->update(['is_active' => !$z->is_active]);
    }

    public function confirmDelete(int $id): void
    {
        $z = ShippingZone::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $z->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        ShippingZone::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} deleted.");
    }

    protected function resetForm(): void
    {
        $this->reset(['name','description','selectedCountries','countrySearch','editingId']);
        $this->isActive  = true;
        $this->isEditing = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        $zones = ShippingZone::withCount(['countries', 'methods'])
            ->with('methods')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.shipping.shipping-zones', [
            'zones'             => $zones,
            'filteredCountries' => $this->filteredCountries,
        ])->layout('layouts.admin');
    }
}