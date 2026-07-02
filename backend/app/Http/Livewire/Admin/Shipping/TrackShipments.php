<?php

namespace App\Http\Livewire\Admin\Shipping;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentTracking;
use Livewire\Component;
use Livewire\WithPagination;

class TrackShipments extends Component
{
    use WithPagination;

    // ── Filters ────────────────────────────────────────────────────────────────
    public string $search       = '';
    public string $statusFilter = '';
    public string $dateFrom     = '';
    public string $dateTo       = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool           $showDetail = false;
    public ?OrderShipment $viewing    = null;

    // ── Create shipment modal ──────────────────────────────────────────────────
    public bool   $showCreateModal = false;
    public string $shipOrderSearch = '';
    public string $shipOrderError  = '';
    public ?Order $shipOrder       = null;
    public string $carrier         = '';
    public string $trackingNumber  = '';
    public string $trackingUrl     = '';
    public string $estimatedDate   = '';
    public string $shipNotes       = '';
    public string $shipStatus      = 'pending';

    // ── Add tracking event modal ───────────────────────────────────────────────
    public bool   $showEventModal   = false;
    public ?int   $eventShipmentId  = null;
    public string $eventStatus      = 'in_transit';
    public string $eventLocation    = '';
    public string $eventDescription = '';
    public string $eventTime        = '';

    // ── Update shipment status modal ───────────────────────────────────────────
    public bool   $showStatusModal  = false;
    public ?int   $updatingId       = null;
    public string $newStatus        = '';

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'dateFrom'     => ['except' => ''],
        'dateTo'       => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    // ── View ───────────────────────────────────────────────────────────────────
    public function viewShipment(int $id): void
    {
        $this->viewing = OrderShipment::with([
            'order',
            'tracking' => fn($q) => $q->orderByDesc('event_time'),
        ])->find($id);
        $this->showDetail = true;
    }

    // ── Create shipment ────────────────────────────────────────────────────────
    public function searchOrder(): void
    {
        $this->shipOrderError = '';
        $this->shipOrder      = null;

        $order = Order::where('order_number', $this->shipOrderSearch)
            ->whereIn('status', ['processing', 'confirmed', 'ready'])
            ->first();

        if (!$order) {
            $this->shipOrderError = 'No open order found with that number.';
            return;
        }

        $this->shipOrder     = $order;
        $this->estimatedDate = now()->addDays(3)->toDateString();
    }

    public function saveShipment(): void
    {
        $this->validate([
            'shipOrder'     => 'required',
            'carrier'       => 'required|string|max:100',
            'trackingNumber'=> 'nullable|string|max:100',
            'estimatedDate' => 'nullable|date',
            'shipStatus'    => 'required|in:pending,shipped,in_transit,out_for_delivery,delivered,failed',
        ]);

        $shipment = OrderShipment::create([
            'order_id'                => $this->shipOrder->id,
            'carrier'                 => $this->carrier,
            'tracking_number'         => $this->trackingNumber ?: null,
            'tracking_url'            => $this->trackingUrl ?: null,
            'status'                  => $this->shipStatus,
            'shipped_at'              => $this->shipStatus !== 'pending' ? now() : null,
            'estimated_delivery_date' => $this->estimatedDate ?: null,
            'notes'                   => $this->shipNotes ?: null,
        ]);

        // Add initial tracking event
        $shipment->addTrackingEvent(
            $this->shipStatus,
            null,
            "Shipment created. Carrier: {$this->carrier}"
        );

        // Update order status
        $this->shipOrder->update(['status' => 'shipped']);

        $this->showCreateModal = false;
        $this->reset(['shipOrderSearch','shipOrder','carrier','trackingNumber','trackingUrl','estimatedDate','shipNotes','shipOrderError']);
        $this->shipStatus = 'pending';

        session()->flash('success', "Shipment {$shipment->shipment_number} created.");
    }

    // ── Add tracking event ─────────────────────────────────────────────────────
    public function openEventModal(int $shipmentId): void
    {
        $this->eventShipmentId  = $shipmentId;
        $this->eventStatus      = 'in_transit';
        $this->eventLocation    = '';
        $this->eventDescription = '';
        $this->eventTime        = now()->format('Y-m-d\TH:i');
        $this->showEventModal   = true;
    }

    public function addEvent(): void
    {
        $this->validate([
            'eventShipmentId'  => 'required|exists:order_shipments,id',
            'eventStatus'      => 'required|string',
            'eventTime'        => 'required|date',
        ]);

        $shipment = OrderShipment::findOrFail($this->eventShipmentId);

        $shipment->addTrackingEvent(
            $this->eventStatus,
            $this->eventLocation ?: null,
            $this->eventDescription ?: null,
            $this->eventTime
        );

        // Auto-update shipment status
        $shipment->update(['status' => $this->eventStatus]);

        if ($this->eventStatus === 'delivered') {
            $shipment->update(['delivered_at' => $this->eventTime]);
            $shipment->order->update(['status' => 'delivered']);
        } elseif ($this->eventStatus === 'shipped') {
            $shipment->update(['shipped_at' => $this->eventTime]);
        }

        // Refresh detail pane
        if ($this->showDetail && $this->viewing?->id === $this->eventShipmentId) {
            $this->viewShipment($this->eventShipmentId);
        }

        $this->showEventModal = false;
        session()->flash('success', 'Tracking event added.');
    }

    // ── Update status ──────────────────────────────────────────────────────────
    public function openStatusModal(int $id, string $current): void
    {
        $this->updatingId      = $id;
        $this->newStatus       = $current;
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        $this->validate([
            'updatingId' => 'required|exists:order_shipments,id',
            'newStatus'  => 'required|in:pending,shipped,in_transit,out_for_delivery,delivered,failed,returned',
        ]);

        $shipment = OrderShipment::findOrFail($this->updatingId);
        $updates  = ['status' => $this->newStatus];

        if ($this->newStatus === 'delivered' && !$shipment->delivered_at) {
            $updates['delivered_at'] = now();
            $shipment->order->update(['status' => 'delivered']);
        }
        if ($this->newStatus === 'shipped' && !$shipment->shipped_at) {
            $updates['shipped_at'] = now();
        }

        $shipment->update($updates);

        if ($this->showDetail && $this->viewing?->id === $this->updatingId) {
            $this->viewShipment($this->updatingId);
        }

        $this->showStatusModal = false;
        session()->flash('success', 'Shipment status updated.');
    }

    public function getSummaryProperty(): array
    {
        return OrderShipment::selectRaw("
            COUNT(*)                                                        AS total,
            COUNT(*) FILTER (WHERE status = 'pending')                     AS pending,
            COUNT(*) FILTER (WHERE status IN ('shipped','in_transit','out_for_delivery')) AS in_transit,
            COUNT(*) FILTER (WHERE status = 'delivered')                   AS delivered,
            COUNT(*) FILTER (WHERE status = 'failed')                      AS failed
        ")->first()->toArray();
    }

    public function render()
    {
        $shipments = OrderShipment::with(['order'])
            ->withCount('tracking')
            ->when($this->search, fn($q) =>
                $q->where('shipment_number', 'ilike', "%{$this->search}%")
                  ->orWhere('tracking_number', 'ilike', "%{$this->search}%")
                  ->orWhere('carrier', 'ilike', "%{$this->search}%")
                  ->orWhereHas('order', fn($oq) =>
                      $oq->where('order_number', 'ilike', "%{$this->search}%")
                         ->orWhere('customer_email', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $statuses = ['pending','shipped','in_transit','out_for_delivery','delivered','failed','returned'];

        return view('livewire.admin.shipping.track-shipments', [
            'shipments' => $shipments,
            'summary'   => $this->summary,
            'statuses'  => $statuses,
        ])->layout('layouts.admin');
    }
}