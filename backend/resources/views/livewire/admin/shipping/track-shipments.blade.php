<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-box"></i><span>Shipping</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Track Shipments</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Track Shipments</h1>
            <p class="mt-0.5 text-sm text-primary-300">Create shipments against orders and log tracking events as packages move.</p>
        </div>
        <button wire:click="$set('showCreateModal', true)"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Ship Order
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php $cards = [
            ['Total',      $summary['total'],      'bi-box-seam',         'border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Pending',    $summary['pending'],    'bi-hourglass-split',  'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['In Transit', $summary['in_transit'], 'bi-truck',            'border-info-200',     'bg-info-50',     'text-info-500',     'text-info-700'],
            ['Delivered',  $summary['delivered'],  'bi-check-circle',     'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Failed',     $summary['failed'],     'bi-exclamation-circle','border-danger-200',  'bg-danger-50',   'text-danger-500',   'text-danger-600'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon }} {{ $ic }} text-lg"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-xl font-bold {{ $vc }} mt-0.5 tabular-nums">{{ $value }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-12 h-12 rounded-full {{ $ibg }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Shipment #, tracking #, carrier, order # or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'pending' => 'Pending', 'in_transit' => 'In Transit', 'delivered' => 'Delivered', 'failed' => 'Failed'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Shipment #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Carrier</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Tracking #</th>
                    <th wire:click="sort('created_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Created <i class="bi bi-arrow-{{ $sortBy==='created_at'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Est. Delivery</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Events</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($shipments as $shipment)
                    @php $badge = match($shipment->status) {
                        'pending'           => 'bg-warning-50 text-warning-700 border border-warning-200',
                        'shipped'           => 'bg-info-50 text-info-700 border border-info-200',
                        'in_transit'        => 'bg-info-50 text-info-700 border border-info-200',
                        'out_for_delivery'  => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                        'delivered'         => 'bg-success-50 text-success-700 border border-success-200',
                        'failed'            => 'bg-danger-50 text-danger-600 border border-danger-200',
                        'returned'          => 'bg-warning-50 text-warning-700 border border-warning-200',
                        default             => 'bg-primary-50 text-primary-400 border border-primary-100',
                    }; @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewShipment({{ $shipment->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $shipment->shipment_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="text-xs font-mono text-primary-400">{{ $shipment->order?->order_number ?? '-' }}</code>
                        </td>
                        <td class="px-5 py-3.5 text-sm font-medium text-primary-600">{{ $shipment->carrier }}</td>
                        <td class="px-5 py-3.5">
                            @if($shipment->tracking_number)
                                @if($shipment->tracking_url)
                                    <a href="{{ $shipment->tracking_url }}" target="_blank"
                                       class="font-mono text-xs text-info-600 hover:text-info-800 underline transition">{{ $shipment->tracking_number }}</a>
                                @else
                                    <code class="font-mono text-xs text-primary-500">{{ $shipment->tracking_number }}</code>
                                @endif
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs text-primary-400 whitespace-nowrap">{{ $shipment->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-5 py-3.5 text-xs {{ $shipment->estimated_delivery_date && $shipment->estimated_delivery_date->isPast() && !$shipment->isDelivered() ? 'text-danger-600 font-semibold' : 'text-primary-400' }} whitespace-nowrap">
                            {{ $shipment->estimated_delivery_date?->format('d M Y') ?? '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">{{ $shipment->tracking_count }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ ucfirst(str_replace('_',' ',$shipment->status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewShipment({{ $shipment->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openEventModal({{ $shipment->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Add tracking event">
                                    <i class="bi bi-geo-alt text-sm"></i>
                                </button>
                                <button wire:click="openStatusModal({{ $shipment->id }},'{{ $shipment->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-warning-600 hover:bg-warning-50 transition" title="Update status">
                                    <i class="bi bi-pencil-square text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-16 text-center">
                        <i class="bi bi-box-seam text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No shipments found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($shipments->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $shipments->links() }}</div>
        @endif
    </div>

    {{-- ═══ DETAIL SLIDE-OVER ═══ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->shipment_number }}</code>
                        @php $sb=match($viewing->status){'delivered'=>'bg-success-50 text-success-700 border border-success-200','failed'=>'bg-danger-50 text-danger-600 border border-danger-200','pending'=>'bg-warning-50 text-warning-700 border border-warning-200',default=>'bg-info-50 text-info-700 border border-info-200'}; @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">{{ ucfirst(str_replace('_',' ',$viewing->status)) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openEventModal({{ $viewing->id }})"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-info-600 hover:border-info-200 transition">
                            <i class="bi bi-geo-alt text-xs"></i> Add Event
                        </button>
                        <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    {{-- Info grid --}}
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Order',       $viewing->order?->order_number ?? '-'],
                            ['Carrier',     $viewing->carrier],
                            ['Tracking #',  $viewing->tracking_number ?? '-'],
                            ['Shipped At',  $viewing->shipped_at?->format('d M Y, H:i') ?? '-'],
                            ['Est. Delivery',$viewing->estimated_delivery_date?->format('d M Y') ?? '-'],
                            ['Delivered At',$viewing->delivered_at?->format('d M Y, H:i') ?? '-'],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->tracking_url)
                        <a href="{{ $viewing->tracking_url }}" target="_blank"
                           class="inline-flex items-center gap-2 rounded-xl bg-info-50 border border-info-200 px-4 py-2.5 text-xs font-semibold text-info-700 hover:bg-info-100 transition">
                            <i class="bi bi-box-arrow-up-right"></i> Track on carrier website
                        </a>
                    @endif
                    {{-- Tracking timeline --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">Tracking History</p>
                        @if($viewing->tracking->count())
                            <div class="relative">
                                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-primary-100"></div>
                                <div class="space-y-4">
                                    @foreach($viewing->tracking as $event)
                                        @php $isLatest = $loop->first; @endphp
                                        <div class="relative flex gap-4 items-start">
                                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center relative z-10
                                                         {{ $isLatest ? 'bg-primary-500 border-2 border-primary-300' : 'bg-white border-2 border-primary-200' }}">
                                                <i class="bi {{ match(true) {
                                                    str_contains($event->status,'deliver') => 'bi-house-check',
                                                    str_contains($event->status,'transit') => 'bi-truck',
                                                    str_contains($event->status,'out') => 'bi-geo',
                                                    str_contains($event->status,'fail') => 'bi-exclamation',
                                                    str_contains($event->status,'ship') => 'bi-box-seam',
                                                    default => 'bi-circle'
                                                } }} text-xs {{ $isLatest ? 'text-white' : 'text-primary-400' }}"></i>
                                            </div>
                                            <div class="flex-1 min-w-0 pb-1">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="text-sm font-semibold text-primary-600 capitalize">{{ str_replace('_',' ',$event->status) }}</p>
                                                    <p class="text-[11px] text-primary-300 flex-shrink-0 whitespace-nowrap">{{ $event->event_time->format('d M, H:i') }}</p>
                                                </div>
                                                @if($event->location)
                                                    <p class="text-xs text-primary-400 mt-0.5"><i class="bi bi-geo-alt mr-1"></i>{{ $event->location }}</p>
                                                @endif
                                                @if($event->description)
                                                    <p class="text-xs text-primary-500 mt-1 leading-relaxed">{{ $event->description }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="rounded-xl bg-primary-50/50 border border-dashed border-primary-200 px-4 py-8 text-center">
                                <i class="bi bi-geo-alt text-2xl text-primary-200 block mb-2"></i>
                                <p class="text-xs text-primary-300">No tracking events yet.</p>
                            </div>
                        @endif
                    </div>
                    @if($viewing->notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ CREATE SHIPMENT MODAL ═══ --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showCreateModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showCreateModal',false)"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Ship an Order</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Enter an order number to create a shipment record.</p>
                    </div>
                    <button wire:click="$set('showCreateModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    {{-- Order lookup --}}
                    @if(!$shipOrder)
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Order Number</label>
                            <div class="flex gap-2">
                                <input wire:model="shipOrderSearch" wire:keydown.enter="searchOrder" type="text" placeholder="e.g. #10001"
                                       class="flex-1 rounded-xl border border-primary-200 px-4 py-3 text-sm text-primary-600 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                                <button wire:click="searchOrder" wire:loading.attr="disabled"
                                        class="rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-3 text-sm font-semibold text-white transition disabled:opacity-60">
                                    <span wire:loading.remove wire:target="searchOrder"><i class="bi bi-search"></i></span>
                                    <span wire:loading wire:target="searchOrder"><i class="bi bi-arrow-clockwise animate-spin"></i></span>
                                </button>
                            </div>
                            @if($shipOrderError)
                                <p class="text-xs text-danger-500 mt-1.5 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $shipOrderError }}</p>
                            @endif
                        </div>
                    @else
                        <div class="rounded-xl bg-success-50 border border-success-200 px-4 py-3 flex items-center gap-3">
                            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-success-700">{{ $shipOrder->order_number }}</p>
                                <p class="text-xs text-success-600 mt-0.5">{{ $shipOrder->customer_email }} · {{ $shipOrder->status }}</p>
                            </div>
                            <button wire:click="$set('shipOrder',null); $set('shipOrderSearch','')"
                                    class="ml-auto text-success-400 hover:text-success-600 transition flex-shrink-0">
                                <i class="bi bi-x-circle text-sm"></i>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Carrier <span class="text-danger-500">*</span></label>
                                <input wire:model="carrier" type="text" placeholder="e.g. DHL, G4S, Wells Fargo"
                                       class="w-full border {{ $errors->has('carrier') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                @error('carrier')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Tracking Number</label>
                                <input wire:model="trackingNumber" type="text" placeholder="Optional"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Est. Delivery</label>
                                <input wire:model="estimatedDate" type="date"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Tracking URL</label>
                                <input wire:model="trackingUrl" type="text" placeholder="https://…"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Initial Status</label>
                                <select wire:model="shipStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                    @foreach($statuses as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_',' ',$s)) }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes</label>
                                <input wire:model="shipNotes" type="text" placeholder="Optional"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                        </div>
                    @endif
                </div>
                @if($shipOrder)
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showCreateModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="saveShipment" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveShipment"><i class="bi bi-box-arrow-right mr-1"></i>Create Shipment</span>
                        <span wire:loading wire:target="saveShipment">Creating…</span>
                    </button>
                </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ═══ ADD TRACKING EVENT MODAL ═══ --}}
    @if($showEventModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showEventModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showEventModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Add Tracking Event</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Log a new status update for this shipment.</p>
                    </div>
                    <button wire:click="$set('showEventModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status <span class="text-danger-500">*</span></label>
                        <select wire:model="eventStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            @foreach($statuses as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_',' ',$s)) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Event Time <span class="text-danger-500">*</span></label>
                        <input wire:model="eventTime" type="datetime-local"
                               class="w-full border {{ $errors->has('eventTime') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('eventTime')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Location</label>
                        <input wire:model="eventLocation" type="text" placeholder="e.g. Nairobi Hub"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                        <textarea wire:model="eventDescription" rows="2" placeholder="e.g. Package received at sorting facility"
                                  class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showEventModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="addEvent" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-info-500 hover:bg-info-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-info-500/20">
                        <span wire:loading.remove wire:target="addEvent"><i class="bi bi-geo-alt mr-1"></i>Add Event</span>
                        <span wire:loading wire:target="addEvent">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ STATUS UPDATE MODAL ═══ --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showStatusModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal',false)"></div>
            <div class="relative w-full max-w-xs rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Update Status</h2>
                    <button wire:click="$set('showStatusModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($statuses as $s)
                            @php $active = $newStatus === $s;
                                 $cfg = match($s){
                                     'delivered'       =>'bg-success-500 border-success-500 text-white',
                                     'failed','returned'=>'bg-danger-500 border-danger-500 text-white',
                                     'pending'         =>'bg-warning-500 border-warning-500 text-white',
                                     default           =>'bg-primary-500 border-primary-500 text-white',
                                 };
                            @endphp
                            <button wire:click="$set('newStatus','{{ $s }}')"
                                    class="py-2 rounded-xl border text-xs font-semibold transition capitalize
                                           {{ $active ? $cfg : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                {{ str_replace('_',' ',$s) }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="updateStatus" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="updateStatus">Save</span>
                        <span wire:loading wire:target="updateStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>