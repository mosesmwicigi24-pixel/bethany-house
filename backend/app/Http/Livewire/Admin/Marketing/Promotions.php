<?php

namespace App\Http\Livewire\Admin\Marketing;

use App\Models\Promotion;
use Livewire\Component;
use Livewire\WithPagination;

class Promotions extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $typeFilter   = '';
    public string $statusFilter = '';
    public string $sortBy       = 'priority';
    public string $sortDir      = 'desc';

    // ── Form modal ─────────────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;

    public string $name          = '';
    public string $description   = '';
    public string $type          = 'product_discount';
    public string $discountValue = '';
    public string $discountType  = 'percentage';
    public bool   $isActive      = true;
    public bool   $isExclusive   = false;
    public string $startsAt      = '';
    public string $endsAt        = '';
    public int    $priority      = 0;
    public string $maxUses       = '';
    public array  $conditions    = [];

    // ── New condition builder ──────────────────────────────────────────────────
    public string $condKey   = '';
    public string $condValue = '';

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool       $showDetail = false;
    public ?Promotion $viewing    = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'typeFilter'   => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function viewPromotion(int $id): void
    {
        $this->viewing    = Promotion::find($id);
        $this->showDetail = true;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $p = Promotion::findOrFail($id);
        $this->editingId     = $id;
        $this->isEditing     = true;
        $this->name          = $p->name;
        $this->description   = $p->description ?? '';
        $this->type          = $p->type;
        $this->discountValue = $p->discount_value ? (string) $p->discount_value : '';
        $this->discountType  = $p->discount_type;
        $this->isActive      = $p->is_active;
        $this->isExclusive   = $p->is_exclusive;
        $this->startsAt      = $p->starts_at ? $p->starts_at->format('Y-m-d\TH:i') : '';
        $this->endsAt        = $p->ends_at   ? $p->ends_at->format('Y-m-d\TH:i')   : '';
        $this->priority      = $p->priority;
        $this->maxUses       = $p->max_uses ? (string) $p->max_uses : '';
        $this->conditions    = $p->conditions ?? [];
        $this->showModal     = true;
        $this->showDetail    = false;
        $this->resetErrorBag();
    }

    public function addCondition(): void
    {
        if ($this->condKey) {
            $this->conditions[] = ['key' => $this->condKey, 'value' => $this->condValue];
            $this->condKey   = '';
            $this->condValue = '';
        }
    }

    public function removeCondition(int $idx): void
    {
        array_splice($this->conditions, $idx, 1);
    }

    public function save(): void
    {
        $this->validate([
            'name'         => 'required|string|max:150',
            'type'         => 'required|in:product_discount,category_discount,bundle,buy_x_get_y,flash_sale',
            'discountValue'=> 'nullable|numeric|min:0',
            'discountType' => 'required|in:percentage,fixed',
            'startsAt'     => 'nullable|date',
            'endsAt'       => 'nullable|date|after_or_equal:startsAt',
            'priority'     => 'integer|min:0',
            'maxUses'      => 'nullable|integer|min:1',
        ]);

        $data = [
            'name'           => $this->name,
            'description'    => $this->description ?: null,
            'type'           => $this->type,
            'discount_value' => $this->discountValue ?: null,
            'discount_type'  => $this->discountType,
            'conditions'     => !empty($this->conditions) ? $this->conditions : null,
            'is_active'      => $this->isActive,
            'is_exclusive'   => $this->isExclusive,
            'starts_at'      => $this->startsAt ?: null,
            'ends_at'        => $this->endsAt   ?: null,
            'priority'       => $this->priority,
            'max_uses'       => $this->maxUses ?: null,
            'created_by'     => auth()->id(),
        ];

        if ($this->isEditing) {
            Promotion::findOrFail($this->editingId)->update($data);
            $msg = 'Promotion updated.';
        } else {
            Promotion::create($data);
            $msg = 'Promotion created.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    public function toggleActive(int $id): void
    {
        $p = Promotion::findOrFail($id);
        $p->update(['is_active' => !$p->is_active]);
    }

    public function confirmDelete(int $id): void
    {
        $p = Promotion::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $p->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        Promotion::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} deleted.");
    }

    protected function resetForm(): void
    {
        $this->reset([
            'name','description','discountValue','startsAt','endsAt',
            'maxUses','conditions','condKey','condValue','editingId',
        ]);
        $this->type         = 'product_discount';
        $this->discountType = 'percentage';
        $this->isActive     = true;
        $this->isExclusive  = false;
        $this->priority     = 0;
        $this->resetErrorBag();
    }

    public function getSummaryProperty(): array
    {
        return Promotion::selectRaw("
            COUNT(*)                                                        AS total,
            COUNT(*) FILTER (WHERE is_active = true
                AND (starts_at IS NULL OR starts_at <= NOW())
                AND (ends_at   IS NULL OR ends_at   >= NOW()))              AS running,
            COUNT(*) FILTER (WHERE starts_at > NOW())                       AS scheduled,
            COUNT(*) FILTER (WHERE ends_at < NOW())                         AS expired,
            COALESCE(SUM(times_used), 0)                                    AS total_uses
        ")->first()->toArray();
    }

    public function render()
    {
        $promotions = Promotion::when($this->search, fn($q) =>
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('description', 'ilike', "%{$this->search}%")
            )
            ->when($this->typeFilter, fn($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter === 'running',  fn($q) => $q->active())
            ->when($this->statusFilter === 'scheduled',fn($q) => $q->where('starts_at', '>', now()))
            ->when($this->statusFilter === 'expired',  fn($q) => $q->where('ends_at', '<', now()))
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.marketing.promotions', [
            'promotions' => $promotions,
            'summary'    => $this->summary,
            'types'      => ['product_discount','category_discount','bundle','buy_x_get_y','flash_sale'],
        ])->layout('layouts.admin');
    }
}