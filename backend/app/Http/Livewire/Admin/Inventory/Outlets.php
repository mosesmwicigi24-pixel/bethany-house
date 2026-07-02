<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class Outlets extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = '';
    public string $statusFilter = '';

    // Modal state
    public bool $showModal = false;
    public bool $isEditing = false;
    public ?int $editingId = null;

    public string $code = '';
    public string $name = '';
    public string $outletType = 'store';
    public string $email = '';
    public string $phone = '';
    public string $addressLine1 = '';
    public string $addressLine2 = '';
    public string $city = '';
    public string $stateProvince = '';
    public string $postalCode = '';
    public string $countryCode = '';
    public bool $isActive = true;
    public bool $isPickupLocation = false;

    // View operating hours
    public bool $showHoursModal = false;
    public ?Outlet $viewingOutlet = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'typeFilter'   => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    protected function rules(): array
    {
        $uniqueCode = $this->isEditing ? ",{$this->editingId}" : '';
        return [
            'code'            => "required|string|max:50|unique:outlets,code{$uniqueCode}",
            'name'            => 'required|string|max:255',
            'outletType'      => 'required|in:store,warehouse,online,popup',
            'email'           => 'nullable|email|max:255',
            'phone'           => 'nullable|string|max:20',
            'addressLine1'    => 'nullable|string|max:255',
            'addressLine2'    => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:100',
            'stateProvince'   => 'nullable|string|max:100',
            'postalCode'      => 'nullable|string|max:20',
            'countryCode'     => 'nullable|string|size:2',
            'isActive'        => 'boolean',
            'isPickupLocation'=> 'boolean',
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingTypeFilter(): void { $this->resetPage(); }

    public function openCreate(): void
    {
        $this->reset(['code', 'name', 'email', 'phone', 'addressLine1', 'addressLine2', 'city', 'stateProvince', 'postalCode', 'countryCode']);
        $this->outletType       = 'store';
        $this->isActive         = true;
        $this->isPickupLocation = false;
        $this->isEditing        = false;
        $this->editingId        = null;
        $this->showModal        = true;
    }

    public function openEdit(int $id): void
    {
        $outlet = Outlet::findOrFail($id);
        $this->code             = $outlet->code;
        $this->name             = $outlet->name;
        $this->outletType       = $outlet->outlet_type;
        $this->email            = $outlet->email ?? '';
        $this->phone            = $outlet->phone ?? '';
        $this->addressLine1     = $outlet->address_line1 ?? '';
        $this->addressLine2     = $outlet->address_line2 ?? '';
        $this->city             = $outlet->city ?? '';
        $this->stateProvince    = $outlet->state_province ?? '';
        $this->postalCode       = $outlet->postal_code ?? '';
        $this->countryCode      = $outlet->country_code ?? '';
        $this->isActive         = $outlet->is_active;
        $this->isPickupLocation = $outlet->is_pickup_location;
        $this->isEditing        = true;
        $this->editingId        = $id;
        $this->showModal        = true;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'code'               => strtoupper($this->code),
            'name'               => $this->name,
            'outlet_type'        => $this->outletType,
            'email'              => $this->email ?: null,
            'phone'              => $this->phone ?: null,
            'address_line1'      => $this->addressLine1 ?: null,
            'address_line2'      => $this->addressLine2 ?: null,
            'city'               => $this->city ?: null,
            'state_province'     => $this->stateProvince ?: null,
            'postal_code'        => $this->postalCode ?: null,
            'country_code'       => $this->countryCode ? strtoupper($this->countryCode) : null,
            'is_active'          => $this->isActive,
            'is_pickup_location' => $this->isPickupLocation,
        ];

        if ($this->isEditing) {
            Outlet::findOrFail($this->editingId)->update($data);
            session()->flash('success', 'Outlet updated successfully.');
        } else {
            Outlet::create($data);
            session()->flash('success', 'Outlet created successfully.');
        }

        $this->showModal = false;
    }

    public function toggleStatus(int $id): void
    {
        $outlet = Outlet::findOrFail($id);
        $outlet->update(['is_active' => !$outlet->is_active]);
    }

    public function viewHours(int $id): void
    {
        $this->viewingOutlet = Outlet::findOrFail($id);
        $this->showHoursModal = true;
    }

    public function render()
    {
        $outlets = Outlet::withCount('inventoryItems')
            ->when($this->search, fn($q) =>
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('code', 'ilike', "%{$this->search}%")
                  ->orWhere('city', 'ilike', "%{$this->search}%")
            )
            ->when($this->typeFilter, fn($q) => $q->where('outlet_type', $this->typeFilter))
            ->when($this->statusFilter === 'active', fn($q) => $q->active())
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin.inventory.outlets', [
            'outlets'     => $outlets,
            'outletTypes' => ['store', 'warehouse', 'online', 'popup'],
        ])->layout('layouts.admin');
    }
}