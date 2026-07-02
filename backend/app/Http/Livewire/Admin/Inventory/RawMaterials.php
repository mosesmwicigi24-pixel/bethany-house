<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\Supplier;
use Livewire\Component;
use Livewire\WithPagination;

class RawMaterials extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';
    public string $typeFilter = '';
    public string $statusFilter = '';

    // Create/Edit modal
    public bool $showModal = false;
    public bool $isEditing = false;
    public ?int $editingId = null;

    public string $code = '';
    public string $name = '';
    public string $description = '';
    public string $materialType = '';
    public string $unitOfMeasure = '';
    public string $costPerUnit = '';
    public string $reorderPoint = '';
    public string $reorderQuantity = '';
    public string $supplierId = '';
    public bool $isActive = true;

    // Stock adjustment modal
    public bool $showStockModal = false;
    public ?int $adjustingMaterialId = null;
    public string $adjustOutletId = '';
    public int $adjustQty = 0;
    public string $adjustType = 'purchase';
    public string $adjustNotes = '';

    protected $queryString = [
        'search'       => ['except' => ''],
        'typeFilter'   => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    protected function rules(): array
    {
        return [
            'code'           => 'required|string|max:50',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'materialType'   => 'required|string|max:50',
            'unitOfMeasure'  => 'required|string|max:20',
            'costPerUnit'    => 'required|numeric|min:0',
            'reorderPoint'   => 'required|numeric|min:0',
            'reorderQuantity'=> 'required|numeric|min:0',
            'supplierId'     => 'nullable|exists:suppliers,id',
            'isActive'       => 'boolean',
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingTypeFilter(): void { $this->resetPage(); }

    public function openCreate(): void
    {
        $this->reset(['code', 'name', 'description', 'materialType', 'unitOfMeasure', 'costPerUnit', 'reorderPoint', 'reorderQuantity', 'supplierId']);
        $this->isActive  = true;
        $this->isEditing = false;
        $this->editingId = null;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $material = Material::findOrFail($id);
        $this->code            = $material->code;
        $this->name            = $material->name;
        $this->description     = $material->description ?? '';
        $this->materialType    = $material->material_type;
        $this->unitOfMeasure   = $material->unit_of_measure;
        $this->costPerUnit     = $material->cost_per_unit;
        $this->reorderPoint    = $material->reorder_point;
        $this->reorderQuantity = $material->reorder_quantity;
        $this->supplierId      = $material->supplier_id ?? '';
        $this->isActive        = $material->is_active;
        $this->isEditing       = true;
        $this->editingId       = $id;
        $this->showModal       = true;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'code'             => $this->code,
            'name'             => $this->name,
            'description'      => $this->description,
            'material_type'    => $this->materialType,
            'unit_of_measure'  => $this->unitOfMeasure,
            'cost_per_unit'    => $this->costPerUnit,
            'reorder_point'    => $this->reorderPoint,
            'reorder_quantity' => $this->reorderQuantity,
            'supplier_id'      => $this->supplierId ?: null,
            'is_active'        => $this->isActive,
        ];

        if ($this->isEditing) {
            Material::findOrFail($this->editingId)->update($data);
            session()->flash('success', 'Material updated successfully.');
        } else {
            Material::create($data);
            session()->flash('success', 'Material created successfully.');
        }

        $this->showModal = false;
    }

    public function openStockAdjust(int $materialId): void
    {
        $this->adjustingMaterialId = $materialId;
        $this->adjustQty   = 0;
        $this->adjustType  = 'purchase';
        $this->adjustNotes = '';
        $this->showStockModal = true;
    }

    public function saveStockAdjust(): void
    {
        $this->validate([
            'adjustingMaterialId' => 'required|exists:materials,id',
            'adjustOutletId'      => 'required|exists:outlets,id',
            'adjustQty'           => 'required|integer|not_in:0',
            'adjustType'          => 'required|string',
        ]);

        $stock = MaterialInventory::firstOrCreate(
            ['material_id' => $this->adjustingMaterialId, 'outlet_id' => $this->adjustOutletId],
            ['quantity_on_hand' => 0]
        );

        $stock->quantity_on_hand += $this->adjustQty;
        $stock->save();

        $this->showStockModal = false;
        session()->flash('success', 'Stock updated.');
    }

    public function render()
    {
        $materials = Material::with(['supplier', 'inventory'])
            ->when($this->search, fn($q) =>
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('code', 'ilike', "%{$this->search}%")
            )
            ->when($this->typeFilter, fn($q) => $q->where('material_type', $this->typeFilter))
            ->when($this->statusFilter === 'active', fn($q) => $q->active())
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.inventory.raw-materials', [
            'materials'     => $materials,
            'suppliers'     => Supplier::orderBy('name')->get(),
            'outlets'       => Outlet::active()->orderBy('name')->get(),
            'materialTypes' => ['raw', 'packaging', 'consumable', 'other'],
        ])->layout('layouts.admin');
    }
}