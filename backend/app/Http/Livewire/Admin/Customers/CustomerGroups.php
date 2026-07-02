<?php

namespace App\Http\Livewire\Admin\Customers;

use App\Models\Customer;
use App\Models\Order;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerGroups extends Component
{
    use WithPagination;

    // ── Segment drill-down ─────────────────────────────────────────────────────
    public string $activeSegment = '';
    public string $segmentSearch = '';
    public string $sortBy        = 'created_at';
    public string $sortDir       = 'desc';

    // ── Bulk move modal (move customers from one segment to another) ────────────
    public bool   $showMoveModal  = false;
    public string $moveFromSeg    = '';       // e.g. "individual_inactive"
    public string $moveTargetType = '';
    public string $moveTargetStatus = '';
    public array  $selectedIds    = [];
    public bool   $selectAll      = false;

    // ── Edit single customer inline (status / type change within segment) ──────
    public bool   $showEditModal   = false;
    public ?int   $editingId       = null;
    public string $editType        = 'individual';
    public string $editStatus      = 'active';
    public string $editCreditLimit = '';
    public string $editNotes       = '';

    // ── Delete confirmation ────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    // ── Create customer from within a segment ──────────────────────────────────
    public bool   $showCreateModal    = false;
    public string $createFirstName    = '';
    public string $createLastName     = '';
    public string $createEmail        = '';
    public string $createPhone        = '';
    public string $createCompany      = '';
    public string $createType         = 'individual';
    public string $createStatus       = 'active';
    public string $createCurrency     = 'KES';
    public string $createCreditLimit  = '';
    public string $createNotes        = '';

    public function updatedSelectAll(bool $val): void
    {
        $this->selectedIds = $val
            ? $this->segmentCustomers?->pluck('id')->map(fn($id) => (string)$id)->toArray() ?? []
            : [];
    }

    public function selectSegment(string $segment): void
    {
        $this->activeSegment = $segment;
        $this->segmentSearch = '';
        $this->selectedIds   = [];
        $this->selectAll     = false;
        $this->resetPage();
    }

    // ── Edit single customer ───────────────────────────────────────────────────

    public function openEdit(int $id): void
    {
        $c = Customer::findOrFail($id);
        $this->editingId      = $id;
        $this->editType       = $c->customer_type;
        $this->editStatus     = $c->status;
        $this->editCreditLimit= $c->credit_limit ? (string) $c->credit_limit : '';
        $this->editNotes      = $c->notes ?? '';
        $this->showEditModal  = true;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editingId'      => 'required|exists:customers,id',
            'editType'       => 'required|in:individual,business',
            'editStatus'     => 'required|in:active,inactive,blocked',
            'editCreditLimit'=> 'nullable|numeric|min:0',
            'editNotes'      => 'nullable|string',
        ]);

        Customer::findOrFail($this->editingId)->update([
            'customer_type' => $this->editType,
            'status'        => $this->editStatus,
            'credit_limit'  => $this->editCreditLimit ?: null,
            'notes'         => $this->editNotes ?: null,
        ]);

        $this->showEditModal = false;
        session()->flash('success', 'Customer updated.');
    }

    // ── Delete single ──────────────────────────────────────────────────────────

    public function confirmDelete(int $id): void
    {
        $c = Customer::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $c->full_name ?: $c->email;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        Customer::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        $this->selectedIds = array_filter($this->selectedIds, fn($id) => (int)$id !== $this->deletingId);
        session()->flash('success', "{$this->deletingName} deleted.");
        $this->deletingId   = null;
        $this->deletingName = '';
    }

    // ── Bulk move to different segment ────────────────────────────────────────

    public function openMoveModal(): void
    {
        if (empty($this->selectedIds)) return;
        $this->moveFromSeg      = $this->activeSegment;
        [$this->moveTargetType, $this->moveTargetStatus] = explode('_', $this->activeSegment, 2);
        $this->showMoveModal = true;
    }

    public function saveMove(): void
    {
        $this->validate([
            'moveTargetType'  => 'required|in:individual,business',
            'moveTargetStatus'=> 'required|in:active,inactive,blocked',
            'selectedIds'     => 'required|array|min:1',
        ]);

        $count = count($this->selectedIds);
        Customer::whereIn('id', $this->selectedIds)->update([
            'customer_type' => $this->moveTargetType,
            'status'        => $this->moveTargetStatus,
        ]);

        $this->showMoveModal = false;
        $this->selectedIds   = [];
        $this->selectAll     = false;
        session()->flash('success', "{$count} customer(s) moved to " . ucfirst($this->moveTargetType) . ' / ' . ucfirst($this->moveTargetStatus) . '.');
    }

    // ── Bulk delete selected ───────────────────────────────────────────────────

    public function bulkDelete(): void
    {
        if (empty($this->selectedIds)) return;
        $count = count($this->selectedIds);
        Customer::whereIn('id', $this->selectedIds)->delete();
        $this->selectedIds = [];
        $this->selectAll   = false;
        session()->flash('success', "{$count} customer(s) deleted.");
    }

    // ── Create customer inside a segment ──────────────────────────────────────

    public function openCreate(): void
    {
        $this->createFirstName   = '';
        $this->createLastName    = '';
        $this->createEmail       = '';
        $this->createPhone       = '';
        $this->createCompany     = '';
        $this->createCurrency    = 'KES';
        $this->createCreditLimit = '';
        $this->createNotes       = '';

        // Pre-fill type/status from the current segment
        if ($this->activeSegment) {
            [$this->createType, $this->createStatus] = explode('_', $this->activeSegment, 2);
        } else {
            $this->createType   = 'individual';
            $this->createStatus = 'active';
        }

        $this->showCreateModal = true;
        $this->resetErrorBag();
    }

    public function saveCreate(): void
    {
        $this->validate([
            'createFirstName' => 'required|string|max:100',
            'createLastName'  => 'nullable|string|max:100',
            'createEmail'     => 'nullable|email|unique:customers,email',
            'createPhone'     => 'nullable|string|max:30',
            'createCompany'   => 'nullable|string|max:255',
            'createType'      => 'required|in:individual,business',
            'createStatus'    => 'required|in:active,inactive,blocked',
            'createCurrency'  => 'required|string|size:3',
            'createCreditLimit'=> 'nullable|numeric|min:0',
            'createNotes'     => 'nullable|string',
        ]);

        Customer::create([
            'first_name'         => $this->createFirstName,
            'last_name'          => $this->createLastName ?: null,
            'email'              => $this->createEmail ?: null,
            'phone'              => $this->createPhone ?: null,
            'company'            => $this->createCompany ?: null,
            'customer_type'      => $this->createType,
            'preferred_currency' => $this->createCurrency,
            'credit_limit'       => $this->createCreditLimit ?: null,
            'status'             => $this->createStatus,
            'notes'              => $this->createNotes ?: null,
        ]);

        $this->showCreateModal = false;
        session()->flash('success', 'Customer created successfully.');
    }

    // ── Data ───────────────────────────────────────────────────────────────────

    public function getGroupStatsProperty(): array
    {
        $rows = Customer::selectRaw("
            customer_type,
            status,
            COUNT(*) AS count,
            COALESCE(SUM(loyalty_points), 0)      AS total_loyalty,
            COALESCE(AVG(loyalty_points), 0)       AS avg_loyalty,
            COALESCE(SUM(outstanding_balance), 0)  AS total_balance
        ")
        ->groupBy('customer_type', 'status')
        ->get();

        $spendByType = Order::where('orders.status', 'completed')
            ->join('customers', fn($j) => $j->on('orders.customer_email', '=', 'customers.email'))
            ->selectRaw("customers.customer_type, COALESCE(SUM(orders.total_amount),0) AS total_spend")
            ->groupBy('customers.customer_type')
            ->pluck('total_spend', 'customers.customer_type');

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->customer_type . '_' . $row->status;
            $groups[$key] = [
                'type'          => $row->customer_type,
                'status'        => $row->status,
                'count'         => $row->count,
                'total_loyalty' => $row->total_loyalty,
                'avg_loyalty'   => round($row->avg_loyalty, 0),
                'total_balance' => $row->total_balance,
                'total_spend'   => $spendByType[$row->customer_type] ?? 0,
            ];
        }

        return $groups;
    }

    public function getSegmentCustomersProperty()
    {
        if (!$this->activeSegment) return null;

        [$type, $status] = explode('_', $this->activeSegment, 2);

        return Customer::withCount('orders')
            ->where('customer_type', $type)
            ->where('status', $status)
            ->when($this->segmentSearch, fn($q) =>
                $q->where('email', 'ilike', "%{$this->segmentSearch}%")
                  ->orWhereRaw("first_name || ' ' || last_name ILIKE ?", ["%{$this->segmentSearch}%"])
                  ->orWhere('phone', 'ilike', "%{$this->segmentSearch}%")
            )
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);
    }

    public function render()
    {
        return view('livewire.admin.customers.customer-groups', [
            'groupStats'       => $this->groupStats,
            'segmentCustomers' => $this->segmentCustomers,
        ])->layout('layouts.admin');
    }
}