<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\BillOfMaterial;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class BillOfMaterials extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $statusFilter = 'active';

    // Create/Edit BOM modal
    public bool   $showModal    = false;
    public bool   $isEditing    = false;
    public ?int   $editingId    = null;

    public string $bomProductId = '';
    public string $bomVariantId = '';
    public int    $bomVersion   = 1;
    public bool   $bomIsActive  = true;
    public string $bomNotes     = '';
    public array  $bomItems     = [];

    // Product search for BOM
    public string $bomProductSearch = '';

    // View BOM items
    public bool             $showItems   = false;
    public ?BillOfMaterial  $viewingBom  = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => 'active'],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function getBomProductsProperty()
    {
        if (strlen($this->bomProductSearch) < 2) return collect();
        return Product::with('translations')
            ->active()
            ->where(fn($q) =>
                $q->where('sku', 'ilike', "%{$this->bomProductSearch}%")
                  ->orWhereHas('translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->bomProductSearch}%")
                  )
            )
            ->limit(8)
            ->get();
    }

    public function selectBomProduct(int $id, string $name): void
    {
        $this->bomProductId     = $id;
        $this->bomProductSearch = $name;
    }

    public function openCreate(): void
    {
        $this->reset(['bomProductId', 'bomVariantId', 'bomNotes', 'bomProductSearch']);
        $this->bomIsActive = true;
        $this->bomVersion  = 1;
        $this->bomItems    = [['material_id' => '', 'quantity' => 1, 'unit_of_measure' => 'pcs', 'notes' => '']];
        $this->isEditing   = false;
        $this->editingId   = null;
        $this->showModal   = true;
    }

    public function openEdit(int $id): void
    {
        $bom = BillOfMaterial::with('items.material')->findOrFail($id);
        $this->editingId      = $id;
        $this->isEditing      = true;
        $this->bomProductId   = $bom->product_id;
        $this->bomVariantId   = $bom->product_variant_id ?? '';
        $this->bomVersion     = $bom->version;
        $this->bomIsActive    = $bom->is_active;
        $this->bomNotes       = $bom->notes ?? '';
        $this->bomProductSearch = $bom->product->translations->first()?->name ?? '';
        $this->bomItems = $bom->items->map(fn($item) => [
            'material_id'    => $item->material_id,
            'quantity'       => $item->quantity,
            'unit_of_measure'=> $item->unit_of_measure,
            'notes'          => $item->notes ?? '',
        ])->toArray();
        $this->showModal = true;
    }

    public function addBomLine(): void
    {
        $this->bomItems[] = ['material_id' => '', 'quantity' => 1, 'unit_of_measure' => 'pcs', 'notes' => ''];
    }

    public function removeBomLine(int $idx): void
    {
        array_splice($this->bomItems, $idx, 1);
    }

    public function saveBom(): void
    {
        $this->validate([
            'bomProductId'      => 'required|exists:products,id',
            'bomVersion'        => 'required|integer|min:1',
            'bomItems'          => 'required|array|min:1',
            'bomItems.*.material_id' => 'required|exists:materials,id',
            'bomItems.*.quantity'    => 'required|numeric|min:0.01',
        ]);

        DB::transaction(function () {
            $data = [
                'product_id'         => $this->bomProductId,
                'product_variant_id' => $this->bomVariantId ?: null,
                'version'            => $this->bomVersion,
                'is_active'          => $this->bomIsActive,
                'notes'              => $this->bomNotes ?: null,
            ];

            if ($this->isEditing) {
                $bom = BillOfMaterial::findOrFail($this->editingId);
                $bom->update($data);
                $bom->items()->delete();
            } else {
                $bom = BillOfMaterial::create($data);
            }

            foreach ($this->bomItems as $item) {
                $bom->items()->create([
                    'material_id'     => $item['material_id'],
                    'quantity'        => $item['quantity'],
                    'unit_of_measure' => $item['unit_of_measure'],
                    'notes'           => $item['notes'] ?: null,
                ]);
            }
        });

        $this->showModal = false;
        session()->flash('success', $this->isEditing ? 'BOM updated.' : 'BOM created.');
    }

    public function toggleActive(int $id): void
    {
        $bom = BillOfMaterial::findOrFail($id);
        $bom->update(['is_active' => !$bom->is_active]);
    }

    public function viewBomItems(int $id): void
    {
        $this->viewingBom = BillOfMaterial::with(['items.material', 'product.translations', 'variant'])->find($id);
        $this->showItems  = true;
    }

    public function render()
    {
        $boms = BillOfMaterial::with(['product.translations', 'variant'])
            ->withCount('items')
            ->when($this->search, fn($q) =>
                $q->whereHas('product.translations', fn($tq) =>
                    $tq->where('name', 'ilike', "%{$this->search}%")
                )
            )
            ->when($this->statusFilter === 'active',   fn($q) => $q->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.production.bill-of-materials', [
            'boms'      => $boms,
            'materials' => Material::active()->orderBy('name')->get(),
        ])->layout('layouts.admin');
    }
}