<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Models\ProductionStage;
use Livewire\Component;
use Livewire\WithPagination;

class QualityControl extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $resultFilter = ''; // pass | fail | pending

    // QC inspection modal
    public bool   $showModal       = false;
    public ?int   $inspectingId    = null;
    public string $qcResult        = 'pass';
    public string $qcNotes         = '';
    public array  $checklistItems  = [];
    public string $defectType      = '';
    public string $remedyAction    = '';

    protected $queryString = [
        'search'       => ['except' => ''],
        'resultFilter' => ['except' => ''],
    ];

    protected array $defaultChecklist = [
        'Measurements correct',
        'Seams straight & even',
        'Stitching secure - no loose threads',
        'Fabric quality acceptable',
        'Buttons/zips functional',
        'Lining aligned',
        'Label attached correctly',
        'Final pressing complete',
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function openInspect(int $orderId): void
    {
        $this->inspectingId   = $orderId;
        $this->qcResult       = 'pass';
        $this->qcNotes        = '';
        $this->defectType     = '';
        $this->remedyAction   = '';
        $this->checklistItems = collect($this->defaultChecklist)
            ->map(fn($item) => ['label' => $item, 'checked' => false])
            ->toArray();
        $this->showModal = true;
    }

    public function saveInspection(): void
    {
        $this->validate([
            'inspectingId' => 'required|exists:production_orders,id',
            'qcResult'     => 'required|in:pass,fail,conditional_pass',
            'qcNotes'      => 'nullable|string|max:1000',
        ]);

        $order = ProductionOrder::findOrFail($this->inspectingId);

        $specs         = $order->specifications ?? [];
        $specs['qc']   = [
            'result'        => $this->qcResult,
            'notes'         => $this->qcNotes,
            'defect_type'   => $this->defectType ?: null,
            'remedy_action' => $this->remedyAction ?: null,
            'checked_by'    => auth()->id(),
            'checked_at'    => now()->toISOString(),
            'checklist'     => $this->checklistItems,
        ];

        $newStatus = match ($this->qcResult) {
            'pass', 'conditional_pass' => 'completed',
            'fail'                     => 'in_progress',
            default                    => $order->status,
        };

        $order->update([
            'specifications' => $specs,
            'status'         => $newStatus,
            'completed_at'   => $this->qcResult === 'pass' ? now() : null,
        ]);

        $this->showModal = false;
        session()->flash('success', 'QC inspection saved.');
    }

    /**
     * PostgreSQL JSONB path query helper.
     * Produces:  specifications->'qc'->>'result' = ?
     */
    protected function whereQcResult($query, string $result)
    {
        return $query->whereRaw(
            "specifications->'qc'->>'result' = ?",
            [$result]
        );
    }

    public function getSummaryProperty(): array
    {
        $awaitingQc = ProductionOrder::where('status', 'quality_check')->count();

        $passedToday = ProductionOrder::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->whereRaw("specifications->'qc'->>'result' = ?", ['pass'])
            ->count();

        $failedToday = ProductionOrder::whereDate('updated_at', today())
            ->whereRaw("specifications->'qc'->>'result' = ?", ['fail'])
            ->count();

        return [
            'awaiting_qc'  => $awaitingQc,
            'passed_today' => $passedToday,
            'failed_today' => $failedToday,
        ];
    }

    public function render()
    {
        $orders = ProductionOrder::with(['product.translations', 'variant', 'tasks', 'createdBy'])
            ->whereIn('status', ['quality_check', 'completed'])
            ->when($this->search, fn($q) =>
                $q->where('order_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('product.translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->resultFilter === 'pending', fn($q) =>
                $q->where('status', 'quality_check')
            )
            ->when($this->resultFilter === 'pass', fn($q) =>
                $q->where('status', 'completed')
                  ->whereRaw("specifications->'qc'->>'result' = ?", ['pass'])
            )
            ->when($this->resultFilter === 'fail', fn($q) =>
                $q->whereRaw("specifications->'qc'->>'result' = ?", ['fail'])
            )
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.production.quality-control', [
            'orders'  => $orders,
            'summary' => $this->summary,
        ])->layout('layouts.admin');
    }
}