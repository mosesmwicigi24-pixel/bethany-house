<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\ProductionTask;
use App\Models\ProductionStage;
use App\Models\User;
use Livewire\Component;

class AssignTailors extends Component
{
    public string $stageFilter    = '';
    public string $statusFilter   = 'pending';
    public string $tailorFilter   = '';
    public string $search         = '';

    // Bulk-assign
    public array  $selectedTasks  = [];
    public bool   $selectAll      = false;
    public string $bulkAssignTo   = '';
    public bool   $showBulkModal  = false;

    // Single quick-assign inline
    public ?int   $inlineTaskId   = null;
    public string $inlineAssignTo = '';

    public function updatedSelectAll(bool $val): void
    {
        $this->selectedTasks = $val
            ? $this->unassignedTasks->pluck('id')->map(fn($id) => (string)$id)->toArray()
            : [];
    }

    public function getUnassignedTasksProperty()
    {
        return ProductionTask::with([
            'productionOrder.product.translations',
            'stage',
            'assignedTo',
        ])
        ->when($this->stageFilter,  fn($q) => $q->where('production_stage_id', $this->stageFilter))
        ->when($this->tailorFilter, fn($q) => $q->where('assigned_to', $this->tailorFilter))
        ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
        ->when($this->search, fn($q) =>
            $q->whereHas('productionOrder', fn($oq) =>
                $oq->where('order_number', 'ilike', "%{$this->search}%")
            )
        )
        ->orderBy('created_at')
        ->get();
    }

    public function getTailorWorkloadProperty(): array
    {
        // User has no tasks() relationship, so we compute counts via two
        // bulk queries on production_tasks and merge them onto the user collection.
        $tailors = User::staffUsers()->orderBy('first_name')->get();

        $activeCounts = ProductionTask::selectRaw('assigned_to, COUNT(*) as cnt')
            ->whereNotNull('assigned_to')
            ->whereIn('status', ['pending', 'in_progress'])
            ->groupBy('assigned_to')
            ->pluck('cnt', 'assigned_to');

        $completedCounts = ProductionTask::selectRaw('assigned_to, COUNT(*) as cnt')
            ->whereNotNull('assigned_to')
            ->where('status', 'completed')
            ->groupBy('assigned_to')
            ->pluck('cnt', 'assigned_to');

        return $tailors
            ->map(fn($user) => array_merge($user->toArray(), [
                'active_tasks_count'    => $activeCounts->get($user->id, 0),
                'completed_tasks_count' => $completedCounts->get($user->id, 0),
            ]))
            ->sortBy('active_tasks_count')
            ->keyBy('id')
            ->toArray();
    }

    public function quickAssign(int $taskId, string $userId): void
    {
        if (!$userId) return;
        ProductionTask::findOrFail($taskId)->update(['assigned_to' => $userId]);
        $this->inlineTaskId   = null;
        $this->inlineAssignTo = '';
        session()->flash('success', 'Task assigned.');
    }

    public function openBulkAssign(): void
    {
        if (empty($this->selectedTasks)) return;
        $this->bulkAssignTo  = '';
        $this->showBulkModal = true;
    }

    public function saveBulkAssign(): void
    {
        $this->validate([
            'bulkAssignTo'  => 'required|exists:users,id',
            'selectedTasks' => 'required|array|min:1',
        ]);

        $count = count($this->selectedTasks); // capture before clearing

        ProductionTask::whereIn('id', $this->selectedTasks)
            ->update(['assigned_to' => $this->bulkAssignTo]);

        $this->selectedTasks = [];
        $this->selectAll     = false;
        $this->showBulkModal = false;
        session()->flash('success', "{$count} task(s) assigned successfully.");
    }

    public function unassignTask(int $taskId): void
    {
        ProductionTask::findOrFail($taskId)->update(['assigned_to' => null]);
        session()->flash('success', 'Task unassigned.');
    }

    public function render()
    {
        return view('livewire.admin.production.assign-tailors', [
            'tasks'    => $this->unassignedTasks,
            'tailors'  => User::staffUsers()->orderBy('first_name')->get(),
            'stages'   => ProductionStage::active()->ordered()->get(),
            'workload' => $this->tailorWorkload,
        ])->layout('layouts.admin');
    }
}