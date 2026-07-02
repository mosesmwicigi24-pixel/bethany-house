<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Models\ProductionStage;
use App\Models\Outlet;
use Livewire\Component;

class WorkInProgress extends Component
{
    public string $outletFilter = '';
    public string $search       = '';

    // Drag/drop status move
    public function moveOrder(int $orderId, string $newStatus): void
    {
        $order = ProductionOrder::findOrFail($orderId);
        $data  = ['status' => $newStatus];

        if ($newStatus === 'in_progress' && !$order->started_at) {
            $data['started_at'] = now();
        }
        if ($newStatus === 'completed' && !$order->completed_at) {
            $data['completed_at'] = now();
        }

        $order->update($data);
    }

    // Quick complete a single task from WIP view
    public function completeTask(int $taskId, ?float $hours = null): void
    {
        ProductionTask::findOrFail($taskId)->complete($hours);
    }

    public function getSummaryProperty(): array
    {
        return ProductionOrder::selectRaw("
            COUNT(*) FILTER (WHERE status = 'pending')       AS pending,
            COUNT(*) FILTER (WHERE status = 'in_progress')   AS in_progress,
            COUNT(*) FILTER (WHERE status = 'on_hold')       AS on_hold,
            COUNT(*) FILTER (WHERE status = 'quality_check') AS quality_check,
            COUNT(*) FILTER (WHERE due_date < NOW() AND completed_at IS NULL) AS overdue
        ")->first()->toArray();
    }

    public function getColumnOrdersProperty(): array
    {
        $statuses = ['pending', 'in_progress', 'on_hold', 'quality_check'];

        $orders = ProductionOrder::with([
            'product.translations',
            'variant',
            'tasks',
            'outlet',
        ])
        ->withCount([
            'tasks',
            'tasks as completed_tasks_count' => fn($q) => $q->where('status', 'completed'),
        ])
        ->whereIn('status', $statuses)
        ->when($this->outletFilter, fn($q) => $q->where('outlet_id', $this->outletFilter))
        ->when($this->search, fn($q) =>
            $q->where('order_number', 'ilike', "%{$this->search}%")
              ->orWhereHas('product.translations', fn($tq) =>
                  $tq->where('name', 'ilike', "%{$this->search}%")
              )
        )
        ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
        ->orderBy('due_date')
        ->get()
        ->groupBy('status');

        // Ensure all columns exist
        $columns = [];
        foreach ($statuses as $s) {
            $columns[$s] = $orders->get($s, collect());
        }
        return $columns;
    }

    public function render()
    {
        return view('livewire.admin.production.work-in-progress', [
            'columns' => $this->columnOrders,
            'summary' => $this->summary,
            'outlets' => Outlet::active()->orderBy('name')->get(),
        ])->layout('layouts.admin');
    }
}