<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-truck"></i><span>Procurement</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Purchase Orders</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Purchase Orders</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage supplier purchase orders from draft to delivery.</p>
        </div>
        <a href="{{ route('admin.procurement.purchase-orders.create') }}"
           class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Create PO
        </a>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php $tabs = [
            ''       => ['All',             array_sum([$summary['draft'],$summary['approved'],$summary['pending_receipt'],$summary['completed']]), 'primary'],
            'draft'  => ['Draft',           $summary['draft'],          'warning'],
            'approved'=>['Approved',        $summary['approved'],       'info'],
            'ordered'=>['Pending Receipt',  $summary['pending_receipt'],'secondary'],
            'completed'=>['Completed',      $summary['completed'],      'success'],
        ];
        $cm=['primary'=>'bg-primary-500 text-white border-primary-500','warning'=>'bg-warning-500 text-white border-warning-500','info'=>'bg-info-500 text-white border-info-500','secondary'=>'bg-secondary-600 text-white border-secondary-600','success'=>'bg-success-500 text-white border-success-500'];
        $ci=['primary'=>'bg-white text-primary-400 border-primary-100 hover:border-primary-300','warning'=>'bg-white text-warning-600 border-primary-100 hover:border-warning-300','info'=>'bg-white text-info-600 border-primary-100 hover:border-info-300','secondary'=>'bg-white text-secondary-700 border-primary-100 hover:border-secondary-300','success'=>'bg-white text-success-700 border-primary-100 hover:border-success-300'];
        @endphp
        @foreach($tabs as $val=>[$label,$count,$color])
            <button wire:click="$set('statusFilter','{{ $val }}')"
                    class="rounded-xl border px-3 py-3 text-center transition cursor-pointer {{ $statusFilter===$val ? $cm[$color] : $ci[$color] }}">
                <div class="text-xl font-bold leading-none">{{ number_format($count) }}</div>
                <div class="text-xs mt-1 font-medium opacity-90">{{ $label }}</div>
            </button>
        @endforeach
    </div>

    {{-- Total value strip --}}
    <div class="flex items-center justify-between rounded-xl bg-primary-50/70 border border-primary-100 px-5 py-3.5">
        <div class="flex items-center gap-2 text-sm text-primary-400">
            <i class="bi bi-currency-exchange text-primary-300"></i>
            <span>Total committed value (non-cancelled)</span>
        </div>
        <span class="font-bold text-lg text-primary-600 tabular-nums">KES {{ number_format($summary['total_value'],2) }}</span>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="PO number or supplier name…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="supplierFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Suppliers</option>
            @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <select wire:model.live="paymentFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Payment</option>
            @foreach($paymentStatuses as $ps)<option value="{{ $ps }}">{{ ucfirst($ps) }}</option>@endforeach
        </select>
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">PO Number</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Supplier</th>
                    <th wire:click="sort('order_date')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Order Date <i class="bi bi-arrow-{{ $sortBy==='order_date'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Expected</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Items</th>
                    <th wire:click="sort('total_amount')" class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Total <i class="bi bi-arrow-{{ $sortBy==='total_amount'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Payment</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($orders as $order)
                    @php
                        $isOverdue = $order->expected_delivery_date && $order->expected_delivery_date->isPast() && !in_array($order->status,['completed','cancelled']);
                        $stBadge = match($order->status){
                            'draft'              =>'bg-warning-50 text-warning-700 border border-warning-200',
                            'submitted'          =>'bg-info-50 text-info-700 border border-info-200',
                            'approved'           =>'bg-success-50 text-success-700 border border-success-200',
                            'ordered'            =>'bg-secondary-50 text-secondary-700 border border-secondary-200',
                            'partially_received' =>'bg-info-50 text-info-700 border border-info-200',
                            'completed'          =>'bg-success-50 text-success-700 border border-success-200',
                            'cancelled'          =>'bg-danger-50 text-danger-600 border border-danger-200',
                            default              =>'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                        $payBadge = match($order->payment_status){
                            'paid'    =>'bg-success-50 text-success-700 border border-success-200',
                            'partial' =>'bg-info-50 text-info-700 border border-info-200',
                            default   =>'bg-warning-50 text-warning-700 border border-warning-200',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group {{ $isOverdue?'bg-danger-50/20':'' }}">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewOrder({{ $order->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $order->po_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-sm">
                            <p class="font-medium text-primary-600">{{ $order->supplier?->name }}</p>
                            @if($order->outlet)<p class="text-xs text-primary-300">{{ $order->outlet->name }}</p>@endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">{{ $order->order_date?->format('d M Y') }}</td>
                        <td class="px-5 py-3.5 text-sm whitespace-nowrap {{ $isOverdue ? 'text-danger-600 font-semibold' : 'text-primary-400' }}">
                            {{ $order->expected_delivery_date?->format('d M Y') ?? '-' }}
                            @if($isOverdue)<div class="text-[11px] text-danger-500">Overdue</div>@endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">{{ $order->items_count }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">{{ number_format($order->total_amount,2) }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $payBadge }}">{{ ucfirst($order->payment_status) }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $stBadge }}">
                                {{ str_replace('_',' ',ucfirst($order->status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewOrder({{ $order->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openStatusModal({{ $order->id }},'{{ $order->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Update status">
                                    <i class="bi bi-pencil-square text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                <tr><td colspan="9" class="px-5 py-16 text-center">
                    <i class="bi bi-file-earmark-text text-4xl text-primary-100 block mb-3"></i>
                    <p class="text-sm font-medium text-primary-300">No purchase orders found.</p>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($orders->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $orders->links() }}</div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-2xl bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->po_number }}</code>
                        @php $sb=match($viewing->status){'draft'=>'bg-warning-50 text-warning-700 border border-warning-200','approved'=>'bg-success-50 text-success-700 border border-success-200','completed'=>'bg-success-50 text-success-700 border border-success-200','cancelled'=>'bg-danger-50 text-danger-600 border border-danger-200',default=>'bg-info-50 text-info-700 border border-info-200'}; @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">{{ str_replace('_',' ',ucfirst($viewing->status)) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openStatusModal({{ $viewing->id }},'{{ $viewing->status }}')"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil-square text-xs"></i> Update Status
                        </button>
                        <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    <div class="grid grid-cols-3 gap-3">
                        @foreach([['Supplier',$viewing->supplier?->name??'-'],['Outlet',$viewing->outlet?->name??'-'],['Order Date',$viewing->order_date?->format('d M Y')??'-'],['Expected',$viewing->expected_delivery_date?->format('d M Y')??'-'],['Currency',$viewing->currency_code],['Payment Terms',$viewing->payment_terms??'-']] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-semibold text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Items table --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Line Items</p>
                        <div class="rounded-xl border border-primary-100 overflow-hidden">
                            <table class="min-w-full text-sm">
                                <thead class="bg-primary-50/60 border-b border-primary-100">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Item</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Qty</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Received</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Unit Price</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-primary-50">
                                    @foreach($viewing->items as $item)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-primary-600">{{ $item->description }}</p>
                                            <span class="text-[11px] text-primary-300 capitalize">{{ $item->item_type }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-primary-500">{{ $item->quantity }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums {{ $item->isFullyReceived() ? 'text-success-600' : 'text-warning-600' }} font-semibold">{{ $item->quantity_received }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-primary-400">{{ number_format($item->unit_price,2) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-primary-600">{{ number_format($item->total_price,2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-primary-50 border-t border-primary-200">
                                    <tr>
                                        <td colspan="4" class="px-4 py-3 text-sm font-bold text-primary-500">Total</td>
                                        <td class="px-4 py-3 text-right font-bold text-primary-600 tabular-nums">{{ $viewing->currency_code }} {{ number_format($viewing->total_amount,2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    {{-- GRNs --}}
                    @if($viewing->goodsReceivedNotes->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Goods Received Notes</p>
                            @foreach($viewing->goodsReceivedNotes as $grn)
                                <div class="flex items-center justify-between rounded-xl bg-success-50 border border-success-200 px-4 py-3">
                                    <code class="font-mono text-xs font-bold text-success-700">{{ $grn->grn_number }}</code>
                                    <span class="text-xs text-success-600">{{ $grn->received_date?->format('d M Y') }}</span>
                                    <span class="text-xs text-success-600">by {{ $grn->receivedBy?->name }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

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

    {{-- Status modal --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showStatusModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Update PO Status</h2>
                    <button wire:click="$set('showStatusModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">New Status</label>
                        <select wire:model="newStatus" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            @foreach($statuses as $s)<option value="{{ $s }}">{{ str_replace('_',' ',ucfirst($s)) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
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