<?php

namespace App\Http\Livewire\Admin\Marketing;

use App\Models\Coupon;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Discounts extends Component
{
    use WithPagination;

    // ── Filters ────────────────────────────────────────────────────────────────
    public string $search       = '';
    public string $typeFilter   = '';
    public string $statusFilter = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';

    // ── Create / Edit modal ────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;

    public string $code                   = '';
    public string $description            = '';
    public string $type                   = 'percentage';
    public string $value                  = '';
    public string $minimumOrderAmount     = '';
    public string $maxDiscountAmount      = '';
    public bool   $isActive               = true;
    public string $validFrom              = '';
    public string $validUntil             = '';
    public string $usageLimit             = '';
    public string $usageLimitPerCustomer  = '';

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingCode    = '';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool    $showDetail = false;
    public ?Coupon $viewing    = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'typeFilter'   => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    // ── View ───────────────────────────────────────────────────────────────────
    public function viewCoupon(int $id): void
    {
        $this->viewing    = Coupon::withTrashed()->find($id);
        $this->showDetail = true;
    }

    // ── Create ─────────────────────────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetForm();
        $this->code      = strtoupper(Str::random(8));
        $this->isEditing = false;
        $this->showModal = true;
    }

    // ── Edit ───────────────────────────────────────────────────────────────────
    public function openEdit(int $id): void
    {
        $c = Coupon::findOrFail($id);
        $this->editingId             = $id;
        $this->isEditing             = true;
        $this->code                  = $c->code;
        $this->description           = $c->description ?? '';
        $this->type                  = $c->type;
        $this->value                 = (string) $c->value;
        $this->minimumOrderAmount    = $c->minimum_order_amount ? (string) $c->minimum_order_amount : '';
        $this->maxDiscountAmount     = $c->max_discount_amount  ? (string) $c->max_discount_amount  : '';
        $this->isActive              = $c->is_active;
        $this->validFrom             = $c->valid_from  ? $c->valid_from->format('Y-m-d\TH:i')  : '';
        $this->validUntil            = $c->valid_until ? $c->valid_until->format('Y-m-d\TH:i') : '';
        $this->usageLimit            = $c->usage_limit             ? (string) $c->usage_limit             : '';
        $this->usageLimitPerCustomer = $c->usage_limit_per_customer ? (string) $c->usage_limit_per_customer : '';
        $this->showModal             = true;
        $this->showDetail            = false;
        $this->resetErrorBag();
    }

    // ── Save ───────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $unique = $this->isEditing ? ','. $this->editingId : '';
        $this->validate([
            'code'              => "required|string|max:50|unique:coupons,code{$unique}",
            'type'              => 'required|in:percentage,fixed,free_shipping',
            'value'             => 'required|numeric|min:0',
            'minimumOrderAmount'=> 'nullable|numeric|min:0',
            'maxDiscountAmount' => 'nullable|numeric|min:0',
            'validFrom'         => 'nullable|date',
            'validUntil'        => 'nullable|date|after_or_equal:validFrom',
            'usageLimit'        => 'nullable|integer|min:1',
            'usageLimitPerCustomer' => 'nullable|integer|min:1',
        ]);

        $data = [
            'code'                      => strtoupper($this->code),
            'description'               => $this->description ?: null,
            'type'                      => $this->type,
            'value'                     => $this->value,
            'minimum_order_amount'      => $this->minimumOrderAmount ?: null,
            'max_discount_amount'       => $this->maxDiscountAmount  ?: null,
            'is_active'                 => $this->isActive,
            'valid_from'                => $this->validFrom  ?: null,
            'valid_until'               => $this->validUntil ?: null,
            'usage_limit'               => $this->usageLimit             ?: null,
            'usage_limit_per_customer'  => $this->usageLimitPerCustomer  ?: null,
            'created_by'                => auth()->id(),
        ];

        if ($this->isEditing) {
            Coupon::findOrFail($this->editingId)->update($data);
            $msg = 'Coupon updated successfully.';
        } else {
            Coupon::create($data);
            $msg = 'Coupon created successfully.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    // ── Duplicate ──────────────────────────────────────────────────────────────
    public function duplicate(int $id): void
    {
        $original = Coupon::findOrFail($id);
        $new = $original->replicate();
        $new->code       = strtoupper(Str::random(8));
        $new->times_used = 0;
        $new->save();
        session()->flash('success', "Coupon duplicated as {$new->code}.");
    }

    // ── Toggle active ──────────────────────────────────────────────────────────
    public function toggleActive(int $id): void
    {
        $c = Coupon::findOrFail($id);
        $c->update(['is_active' => !$c->is_active]);
    }

    // ── Delete ─────────────────────────────────────────────────────────────────
    public function confirmDelete(int $id): void
    {
        $c = Coupon::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingCode = $c->code;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        Coupon::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "Coupon {$this->deletingCode} deleted.");
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    protected function resetForm(): void
    {
        $this->reset([
            'code','description','type','value','minimumOrderAmount',
            'maxDiscountAmount','validFrom','validUntil',
            'usageLimit','usageLimitPerCustomer','editingId',
        ]);
        $this->isActive = true;
        $this->type     = 'percentage';
        $this->resetErrorBag();
    }

    public function generateCode(): void
    {
        $this->code = strtoupper(Str::random(8));
    }

    public function getSummaryProperty(): array
    {
        return Coupon::selectRaw("
            COUNT(*)                                           AS total,
            COUNT(*) FILTER (WHERE is_active = true
                AND (valid_until IS NULL OR valid_until >= NOW())
                AND (valid_from  IS NULL OR valid_from  <= NOW())) AS active,
            COUNT(*) FILTER (WHERE valid_until < NOW())        AS expired,
            COALESCE(SUM(times_used), 0)                       AS total_uses
        ")->first()->toArray();
    }

    public function render()
    {
        $coupons = Coupon::withCount([])
            ->when($this->search, fn($q) =>
                $q->where('code', 'ilike', "%{$this->search}%")
                  ->orWhere('description', 'ilike', "%{$this->search}%")
            )
            ->when($this->typeFilter, fn($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter === 'active', fn($q) =>
                $q->where('is_active', true)
                  ->where(fn($sq) => $sq->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                  ->where(fn($sq) => $sq->whereNull('valid_from') ->orWhere('valid_from', '<=', now()))
            )
            ->when($this->statusFilter === 'expired',  fn($q) => $q->where('valid_until', '<', now()))
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->when($this->statusFilter === 'scheduled',fn($q) => $q->where('valid_from', '>', now()))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        return view('livewire.admin.marketing.discounts', [
            'coupons' => $coupons,
            'summary' => $this->summary,
        ])->layout('layouts.admin');
    }
}