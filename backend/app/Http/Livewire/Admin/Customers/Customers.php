<?php

namespace App\Http\Livewire\Admin\Customers;

use App\Models\Customer;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Customers extends Component
{
    use WithPagination;

    // ── Filters ────────────────────────────────────────────────────────────────
    public string $search        = '';
    public string $statusFilter  = '';
    public string $typeFilter    = '';
    public string $sortBy        = 'created_at';
    public string $sortDir       = 'desc';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool      $showDetail = false;
    public ?Customer $viewing    = null;

    // ── Create / Edit modal ────────────────────────────────────────────────────
    public bool   $showFormModal = false;
    public bool   $isEditing     = false;
    public ?int   $editingId     = null;

    public string $firstName         = '';
    public string $lastName          = '';
    public string $email             = '';
    public string $phone             = '';
    public string $company           = '';
    public string $taxId             = '';
    public string $customerType      = 'individual';
    public string $preferredLanguage = 'en';
    public string $preferredCurrency = 'KES';
    public string $creditLimit       = '';
    public string $loyaltyPoints     = '0';
    public string $outstandingBalance= '0';
    public string $formStatus        = 'active';
    public string $notes             = '';

    // ── Status update modal ────────────────────────────────────────────────────
    public bool   $showStatusModal = false;
    public ?int   $updatingId      = null;
    public string $newStatus       = '';

    // ── Delete confirmation ────────────────────────────────────────────────────
    public bool   $showDeleteModal  = false;
    public ?int   $deletingId       = null;
    public string $deletingName     = '';

    // ── Loyalty adjust modal ───────────────────────────────────────────────────
    public bool   $showLoyaltyModal  = false;
    public ?int   $loyaltyCustomerId = null;
    public string $loyaltyAdjust     = '';
    public string $loyaltyType       = 'add'; // add | subtract | set

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'typeFilter'   => ['except' => ''],
    ];

    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingStatusFilter(): void  { $this->resetPage(); }
    public function updatingTypeFilter(): void    { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    // ── View ───────────────────────────────────────────────────────────────────

    public function viewCustomer(int $id): void
    {
        $this->viewing = Customer::with([
            'addresses',
            'orders' => fn($q) => $q->latest()->limit(5),
            'user',
        ])->withCount('orders')->find($id);
        $this->showDetail = true;
    }

    // ── Create ─────────────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetFormFields();
        $this->isEditing    = false;
        $this->editingId    = null;
        $this->showFormModal = true;
        // Close detail if open
        $this->showDetail   = false;
    }

    // ── Edit ───────────────────────────────────────────────────────────────────

    public function openEdit(int $id): void
    {
        $customer = Customer::findOrFail($id);

        $this->editingId          = $id;
        $this->isEditing          = true;
        $this->firstName          = $customer->first_name ?? '';
        $this->lastName           = $customer->last_name ?? '';
        $this->email              = $customer->email ?? '';
        $this->phone              = $customer->phone ?? '';
        $this->company            = $customer->company ?? '';
        $this->taxId              = $customer->tax_id ?? '';
        $this->customerType       = $customer->customer_type ?? 'individual';
        $this->preferredLanguage  = $customer->preferred_language ?? 'en';
        $this->preferredCurrency  = $customer->preferred_currency ?? 'KES';
        $this->creditLimit        = $customer->credit_limit ? (string) $customer->credit_limit : '';
        $this->loyaltyPoints      = (string) ($customer->loyalty_points ?? 0);
        $this->outstandingBalance = (string) ($customer->outstanding_balance ?? 0);
        $this->formStatus         = $customer->status ?? 'active';
        $this->notes              = $customer->notes ?? '';

        $this->showFormModal = true;
        $this->showDetail    = false;
    }

    // ── Save (create or update) ────────────────────────────────────────────────

    public function save(): void
    {
        $emailUnique = $this->isEditing
            ? 'nullable|email|unique:customers,email,' . $this->editingId
            : 'nullable|email|unique:customers,email';

        $this->validate([
            'firstName'        => 'required|string|max:100',
            'lastName'         => 'nullable|string|max:100',
            'email'            => $emailUnique,
            'phone'            => 'nullable|string|max:30',
            'company'          => 'nullable|string|max:255',
            'taxId'            => 'nullable|string|max:100',
            'customerType'     => 'required|in:individual,business',
            'preferredCurrency'=> 'required|string|size:3',
            'creditLimit'      => 'nullable|numeric|min:0',
            'loyaltyPoints'    => 'nullable|integer|min:0',
            'outstandingBalance'=> 'nullable|numeric|min:0',
            'formStatus'       => 'required|in:active,inactive,blocked',
            'notes'            => 'nullable|string',
        ]);

        $data = [
            'first_name'          => $this->firstName,
            'last_name'           => $this->lastName ?: null,
            'email'               => $this->email ?: null,
            'phone'               => $this->phone ?: null,
            'company'             => $this->company ?: null,
            'tax_id'              => $this->taxId ?: null,
            'customer_type'       => $this->customerType,
            'preferred_language'  => $this->preferredLanguage ?: 'en',
            'preferred_currency'  => $this->preferredCurrency,
            'credit_limit'        => $this->creditLimit ?: null,
            'loyalty_points'      => (int) ($this->loyaltyPoints ?? 0),
            'outstanding_balance' => $this->outstandingBalance ?: 0,
            'status'              => $this->formStatus,
            'notes'               => $this->notes ?: null,
        ];

        if ($this->isEditing) {
            Customer::findOrFail($this->editingId)->update($data);
            $msg = 'Customer updated successfully.';
        } else {
            Customer::create($data);
            $msg = 'Customer created successfully.';
        }

        $this->showFormModal = false;
        $this->resetFormFields();
        session()->flash('success', $msg);
    }

    // ── Delete ─────────────────────────────────────────────────────────────────

    public function confirmDelete(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $customer->full_name ?: $customer->email;
        $this->showDeleteModal = true;
        $this->showDetail      = false;
    }

    public function delete(): void
    {
        Customer::findOrFail($this->deletingId)->delete(); // soft delete
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} has been deleted.");
        $this->deletingId   = null;
        $this->deletingName = '';
    }

    // ── Status update ──────────────────────────────────────────────────────────

    public function openStatusModal(int $id, string $current): void
    {
        $this->updatingId      = $id;
        $this->newStatus       = $current;
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        $this->validate([
            'updatingId' => 'required|exists:customers,id',
            'newStatus'  => 'required|in:active,inactive,blocked',
        ]);

        Customer::findOrFail($this->updatingId)->update(['status' => $this->newStatus]);

        if ($this->showDetail && $this->viewing?->id === $this->updatingId) {
            $this->viewCustomer($this->updatingId);
        }

        $this->showStatusModal = false;
        session()->flash('success', 'Customer status updated.');
    }

    // ── Loyalty points ─────────────────────────────────────────────────────────

    public function openLoyaltyModal(int $id): void
    {
        $customer                = Customer::findOrFail($id);
        $this->loyaltyCustomerId = $id;
        $this->loyaltyAdjust     = '';
        $this->loyaltyType       = 'add';
        $this->showLoyaltyModal  = true;
    }

    public function saveLoyalty(): void
    {
        $this->validate([
            'loyaltyCustomerId' => 'required|exists:customers,id',
            'loyaltyAdjust'     => 'required|numeric|min:0',
            'loyaltyType'       => 'required|in:add,subtract,set',
        ]);

        $customer = Customer::findOrFail($this->loyaltyCustomerId);

        $newPoints = match ($this->loyaltyType) {
            'add'      => $customer->loyalty_points + (int) $this->loyaltyAdjust,
            'subtract' => max(0, $customer->loyalty_points - (int) $this->loyaltyAdjust),
            'set'      => (int) $this->loyaltyAdjust,
        };

        $customer->update(['loyalty_points' => $newPoints]);

        // Refresh detail pane if open
        if ($this->showDetail && $this->viewing?->id === $this->loyaltyCustomerId) {
            $this->viewCustomer($this->loyaltyCustomerId);
        }

        $this->showLoyaltyModal = false;
        session()->flash('success', "Loyalty points updated to {$newPoints}.");
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    protected function resetFormFields(): void
    {
        $this->firstName          = '';
        $this->lastName           = '';
        $this->email              = '';
        $this->phone              = '';
        $this->company            = '';
        $this->taxId              = '';
        $this->customerType       = 'individual';
        $this->preferredLanguage  = 'en';
        $this->preferredCurrency  = 'KES';
        $this->creditLimit        = '';
        $this->loyaltyPoints      = '0';
        $this->outstandingBalance = '0';
        $this->formStatus         = 'active';
        $this->notes              = '';
        $this->resetErrorBag();
    }

    public function getSummaryProperty(): array
    {
        return Customer::selectRaw("
            COUNT(*)                                              AS total,
            COUNT(*) FILTER (WHERE status = 'active')            AS active,
            COUNT(*) FILTER (WHERE customer_type = 'business')   AS business,
            COUNT(*) FILTER (WHERE outstanding_balance > 0)      AS with_balance,
            COALESCE(SUM(loyalty_points), 0)                     AS total_loyalty_pts
        ")->first()->toArray();
    }

    public function render()
    {
        $customers = Customer::withCount('orders')
            ->when($this->search, fn($q) =>
                $q->where('customer_number', 'ilike', "%{$this->search}%")
                  ->orWhere('email', 'ilike', "%{$this->search}%")
                  ->orWhereRaw("first_name || ' ' || last_name ILIKE ?", ["%{$this->search}%"])
                  ->orWhere('phone', 'ilike', "%{$this->search}%")
                  ->orWhere('company', 'ilike', "%{$this->search}%")
            )
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter,   fn($q) => $q->where('customer_type', $this->typeFilter))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        return view('livewire.admin.customers.customers', [
            'customers' => $customers,
            'summary'   => $this->summary,
            'statuses'  => ['active', 'inactive', 'blocked'],
        ])->layout('layouts.admin');
    }
}