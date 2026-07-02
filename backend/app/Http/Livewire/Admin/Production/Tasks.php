<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\ProductionTask;
use App\Models\ProductionStage;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Tasks extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $statusFilter = '';
    public string $stageFilter  = '';
    public string $assigneeFilter = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';

    // Assign modal
    public bool   $showAssignModal = false;
    public ?int   $assigningTaskId = null;
    public string $assignToUserId  = '';
    public string $estimatedHours  = '';
    public string $assignNotes     = '';

    // Update status modal
    public bool   $showStatusModal  = false;
    public ?int   $updatingTaskId   = null;
    public string $newStatus        = '';
    public string $actualHours      = '';

    protected $queryString = [
        'search'        => ['except' => ''],
        'statusFilter'  => ['except' => ''],
        'stageFilter'   => ['except' => ''],
        'assigneeFilter'=> ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function openAssign(int $taskId): void
    {
        $task = ProductionTask::find($taskId);
        $this->assigningTaskId = $taskId;
        $this->assignToUserId  = $task->assigned_to ?? '';
        $this->estimatedHours  = $task->estimated_hours ?? '';
        $this->assignNotes     = $task->notes ?? '';
        $this->showAssignModal = true;
    }

    public function saveAssignment(): void
    {
        $this->validate([
            'assigningTaskId' => 'required|exists:production_tasks,id',
            'assignToUserId'  => 'required|exists:users,id',
            'estimatedHours'  => 'nullable|numeric|min:0.5',
        ]);

        ProductionTask::findOrFail($this->assigningTaskId)->update([
            'assigned_to'     => $this->assignToUserId,
            'estimated_hours' => $this->estimatedHours ?: null,
            'notes'           => $this->assignNotes ?: null,
        ]);

        $this->showAssignModal = false;
        session()->flash('success', 'Task assigned successfully.');
    }

    public function openStatusUpdate(int $taskId, string $current): void
    {
        $this->updatingTaskId = $taskId;
        $this->newStatus      = $current;
        $this->actualHours    = '';
        $this->showStatusModal = true;
    }

    public function updateTaskStatus(): void
    {
        $this->validate([
            'updatingTaskId' => 'required|exists:production_tasks,id',
            'newStatus'      => 'required|in:pending,in_progress,completed,blocked',
            'actualHours'    => 'nullable|numeric|min:0',
        ]);

        $task = ProductionTask::findOrFail($this->updatingTaskId);

        match ($this->newStatus) {
            'in_progress' => $task->start(),
            'completed'   => $task->complete($this->actualHours ?: null),
            default       => $task->update(['status' => $this->newStatus]),
        };

        $this->showStatusModal = false;
        session()->flash('success', 'Task status updated.');
    }

    public function getSummaryProperty(): array
    {
        return ProductionTask::selectRaw("
            COUNT(*) FILTER (WHERE status = 'pending')     AS pending,
            COUNT(*) FILTER (WHERE status = 'in_progress') AS in_progress,
            COUNT(*) FILTER (WHERE status = 'completed')   AS completed,
            COUNT(*) FILTER (WHERE status = 'blocked')     AS blocked,
            COUNT(*) FILTER (WHERE assigned_to IS NULL AND status != 'completed') AS unassigned
        ")->first()->toArray();
    }

    public function render()
    {
        $tasks = ProductionTask::with([
            'productionOrder.product.translations',
            'stage',
            'assignedTo',
        ])
        ->when($this->search, fn($q) =>
            $q->whereHas('productionOrder', fn($oq) =>
                $oq->where('order_number', 'ilike', "%{$this->search}%")
            )
        )
        ->when($this->statusFilter,   fn($q) => $q->where('status', $this->statusFilter))
        ->when($this->stageFilter,    fn($q) => $q->where('production_stage_id', $this->stageFilter))
        ->when($this->assigneeFilter, fn($q) => $q->where('assigned_to', $this->assigneeFilter))
        ->orderBy($this->sortBy, $this->sortDir)
        ->paginate(25);

        return view('livewire.admin.production.tasks', [
            'tasks'    => $tasks,
            'summary'  => $this->summary,
            'stages'   => ProductionStage::active()->ordered()->get(),
            'tailors'  => User::staffUsers()->orderBy('first_name')->get(),
            'statuses' => ['pending', 'in_progress', 'completed', 'blocked'],
        ])->layout('layouts.admin');
    }
}