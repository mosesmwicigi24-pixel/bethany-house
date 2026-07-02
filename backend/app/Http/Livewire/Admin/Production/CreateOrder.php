<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\BillOfMaterial;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductVariant;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

class CreateOrder extends Component
{
    // Step management
    public int $step = 1; // 1=Product, 2=Details, 3=Tasks/BOM, 4=Review

    // Step 1 – Product
    public string $productSearch    = '';
    public ?int   $productId        = null;
    public ?int   $variantId        = null;
    public string $productName      = '';

    // Step 2 – Details
    public int    $quantity         = 1;
    public string $priority         = 'normal';
    public string $dueDate          = '';
    public string $outletId         = '';
    public string $customerOrderId  = '';
    public string $notes            = '';
    public array  $specifications   = []; // key => value pairs
    public string $specKey          = '';
    public string $specValue        = '';

    // Step 3 – Tasks & BOM
    public array  $tasks            = [];  // [stage_id, estimated_hours, notes]
    public bool   $loadBom          = true;
    public array  $bomItems         = [];  // [material_id, qty, uom, notes]

    protected $rules = [
        'productId'    => 'required|exists:products,id',
        'quantity'     => 'required|integer|min:1',
        'priority'     => 'required|in:low,normal,high,urgent',
        'dueDate'      => 'required|date|after:today',
        'outletId'     => 'required|exists:outlets,id',
        'customerOrderId' => 'nullable|exists:orders,id',
    ];

    public function mount(): void
    {
        $this->dueDate = now()->addDays(7)->toDateString();
        $this->initTasks();
    }

    protected function initTasks(): void
    {
        $this->tasks = ProductionStage::active()->ordered()->get()
            ->map(fn($stage) => [
                'stage_id'        => $stage->id,
                'stage_name'      => $stage->name,
                'estimated_hours' => '',
                'notes'           => '',
                'include'         => true,
            ])->toArray();
    }

    public function getProductsProperty()
    {
        if (strlen($this->productSearch) < 2) return collect();

        return Product::with(['translations', 'variants'])
            ->active()
            ->where(fn($q) =>
                $q->where('sku', 'ilike', "%{$this->productSearch}%")
                  ->orWhereHas('translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->productSearch}%")
                  )
            )
            ->limit(10)
            ->get();
    }

    public function selectProduct(int $productId, string $name): void
    {
        $this->productId     = $productId;
        $this->productName   = $name;
        $this->productSearch = '';
        $this->variantId     = null;

        // Auto-load BOM items for this product
        $this->loadBomForProduct($productId);
    }

    protected function loadBomForProduct(int $productId, ?int $variantId = null): void
    {
        $bom = BillOfMaterial::with('items.material')
            ->where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('product_variant_id', $variantId))
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($bom) {
            $this->bomItems = $bom->items->map(fn($item) => [
                'material_id'   => $item->material_id,
                'material_name' => $item->material?->name,
                'quantity'      => $item->quantity,
                'unit_of_measure' => $item->unit_of_measure,
                'notes'         => $item->notes ?? '',
            ])->toArray();
        }
    }

    public function addBomItem(): void
    {
        $this->bomItems[] = [
            'material_id'    => '',
            'material_name'  => '',
            'quantity'       => 1,
            'unit_of_measure'=> 'pcs',
            'notes'          => '',
        ];
    }

    public function removeBomItem(int $idx): void
    {
        array_splice($this->bomItems, $idx, 1);
    }

    public function addSpecification(): void
    {
        if ($this->specKey) {
            $this->specifications[$this->specKey] = $this->specValue;
            $this->specKey   = '';
            $this->specValue = '';
        }
    }

    public function removeSpec(string $key): void
    {
        unset($this->specifications[$key]);
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateOnly('productId');
        }
        if ($this->step === 2) {
            $this->validate([
                'quantity'  => 'required|integer|min:1',
                'priority'  => 'required|in:low,normal,high,urgent',
                'dueDate'   => 'required|date',
                'outletId'  => 'required|exists:outlets,id',
            ]);
        }
        $this->step++;
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function save(): void
    {
        $this->validate([
            'productId' => 'required|exists:products,id',
            'quantity'  => 'required|integer|min:1',
            'priority'  => 'required|in:low,normal,high,urgent',
            'dueDate'   => 'required|date',
            'outletId'  => 'required|exists:outlets,id',
        ]);

        DB::transaction(function () {
            $order = ProductionOrder::create([
                'product_id'         => $this->productId,
                'product_variant_id' => $this->variantId ?: null,
                'customer_order_id'  => $this->customerOrderId ?: null,
                'quantity'           => $this->quantity,
                'status'             => 'pending',
                'priority'           => $this->priority,
                'due_date'           => $this->dueDate,
                'outlet_id'          => $this->outletId,
                'notes'              => $this->notes ?: null,
                'specifications'     => $this->specifications ?: null,
                'created_by'         => auth()->id(),
            ]);

            // Create tasks for selected stages
            foreach ($this->tasks as $task) {
                if (!($task['include'] ?? true)) continue;
                ProductionTask::create([
                    'production_order_id' => $order->id,
                    'production_stage_id' => $task['stage_id'],
                    'status'              => 'pending',
                    'estimated_hours'     => $task['estimated_hours'] ?: null,
                    'notes'               => $task['notes'] ?: null,
                ]);
            }

            // Create material allocations
            foreach ($this->bomItems as $item) {
                if (!$item['material_id'] || !$item['quantity']) continue;
                MaterialAllocation::create([
                    'production_order_id' => $order->id,
                    'material_id'         => $item['material_id'],
                    'quantity_required'   => $item['quantity'],
                    'quantity_allocated'  => 0,
                    'quantity_used'       => 0,
                    'quantity_returned'   => 0,
                ]);
            }

            session()->flash('success', "Production order {$order->order_number} created successfully.");
            $this->redirect(route('admin.production.orders'));
        });
    }

    public function render()
    {
        return view('livewire.admin.production.create-order', [
            'products'   => $this->products,
            'outlets'    => Outlet::active()->orderBy('name')->get(),
            'orders'     => Order::where('status', 'processing')->orderByDesc('created_at')->limit(50)->get(),
            'materials'  => Material::active()->orderBy('name')->get(),
            'stages'     => ProductionStage::active()->ordered()->get(),
        ])->layout('layouts.admin');
    }
}