<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-megaphone"></i><span>Marketing</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Email Campaigns</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Email Campaigns</h1>
            <p class="mt-0.5 text-sm text-primary-300">Create and send email campaigns to your customer segments.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Campaign
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @php $cards = [
            ['Total',      $summary['total'],                                  'bi-envelope',         'border-primary-100', 'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Draft',      $summary['draft'],                                  'bi-file-earmark',     'border-primary-200', 'bg-primary-50',  'text-primary-300',  'text-primary-500'],
            ['Scheduled',  $summary['scheduled'],                              'bi-clock',            'border-info-200',    'bg-info-50',     'text-info-500',     'text-info-700'],
            ['Sent',       $summary['sent'],                                   'bi-send-check',       'border-success-200', 'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Total Sent', number_format($summary['total_sent']),              'bi-people',           'border-secondary-200','bg-secondary-50','text-secondary-600','text-secondary-700'],
            ['Opened',     number_format($summary['total_opened']),            'bi-eye',              'border-warning-200', 'bg-warning-50',  'text-warning-500',  'text-warning-700'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon }} {{ $ic }}"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-base font-bold {{ $vc }} mt-0.5 tabular-nums">{{ $value }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full {{ $ibg }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search campaign name or subject…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'draft' => 'Draft', 'scheduled' => 'Scheduled', 'sent' => 'Sent', 'cancelled' => 'Cancelled'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
    </div>

    {{-- Campaigns table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Campaign</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Audience</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Recipients</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Open Rate</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Click Rate</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Scheduled / Sent</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($campaigns as $campaign)
                    @php $badge = match($campaign->status) {
                        'draft'     => 'bg-primary-50 text-primary-400 border border-primary-200',
                        'scheduled' => 'bg-info-50 text-info-700 border border-info-200',
                        'sending'   => 'bg-warning-50 text-warning-700 border border-warning-200',
                        'sent'      => 'bg-success-50 text-success-700 border border-success-200',
                        'cancelled' => 'bg-danger-50 text-danger-600 border border-danger-200',
                        default     => 'bg-primary-50 text-primary-300 border border-primary-100',
                    }; @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewCampaign({{ $campaign->id }})" class="text-left">
                                <p class="font-semibold text-primary-600 hover:text-primary-500 transition text-sm">{{ $campaign->name }}</p>
                                <p class="text-xs text-primary-300 mt-0.5 truncate max-w-[200px]">{{ $campaign->subject }}</p>
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-primary-50 text-primary-500 border border-primary-100 capitalize">
                                {{ str_replace('_',' ',$campaign->audience) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-center tabular-nums text-primary-500 font-medium">{{ number_format($campaign->recipient_count) }}</td>
                        <td class="px-5 py-3.5 text-center">
                            @if($campaign->status === 'sent' && $campaign->sent_count > 0)
                                <span class="text-sm font-semibold {{ $campaign->open_rate >= 20 ? 'text-success-700' : ($campaign->open_rate >= 10 ? 'text-warning-600' : 'text-primary-400') }} tabular-nums">
                                    {{ $campaign->open_rate }}%
                                </span>
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            @if($campaign->status === 'sent' && $campaign->sent_count > 0)
                                <span class="text-sm font-semibold text-info-700 tabular-nums">{{ $campaign->click_rate }}%</span>
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs text-primary-400">
                            @if($campaign->sent_at)
                                <p>Sent {{ $campaign->sent_at->format('d M Y') }}</p>
                            @elseif($campaign->scheduled_at)
                                <p>Scheduled {{ $campaign->scheduled_at->format('d M Y, H:i') }}</p>
                            @else
                                <span class="text-primary-200">Not scheduled</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }} capitalize">{{ $campaign->status }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewCampaign({{ $campaign->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                @if(in_array($campaign->status,['draft','scheduled']))
                                    <button wire:click="openEdit({{ $campaign->id }})"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                        <i class="bi bi-pencil text-sm"></i>
                                    </button>
                                    <button wire:click="cancelCampaign({{ $campaign->id }})"
                                            wire:confirm="Cancel this campaign?"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-warning-600 hover:bg-warning-50 transition" title="Cancel">
                                        <i class="bi bi-x-circle text-sm"></i>
                                    </button>
                                @endif
                                <button wire:click="confirmDelete({{ $campaign->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                    <i class="bi bi-trash3 text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-16 text-center">
                        <i class="bi bi-envelope text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No email campaigns yet.</p>
                        <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Create your first campaign →</button>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($campaigns->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $campaigns->links() }}</div>
        @endif
    </div>

    {{-- ═══ DETAIL SLIDE-OVER ═══ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-xl bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $viewing->name }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $viewing->subject }}</p>
                    </div>
                    <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    @if($viewing->status === 'sent')
                        {{-- Stats grid --}}
                        <div class="grid grid-cols-4 gap-3">
                            @foreach([
                                ['Sent',        $viewing->sent_count,        'text-success-700'],
                                ['Opened',      $viewing->opened_count,      'text-info-700'],
                                ['Clicked',     $viewing->clicked_count,     'text-secondary-700'],
                                ['Bounced',     $viewing->bounced_count,     'text-danger-600'],
                                ['Open Rate',   $viewing->open_rate.'%',     'text-info-700'],
                                ['Click Rate',  $viewing->click_rate.'%',    'text-secondary-700'],
                                ['Unsub',       $viewing->unsubscribed_count,'text-warning-700'],
                                ['Recipients',  $viewing->recipient_count,   'text-primary-600'],
                            ] as [$l,$v,$vc])
                                <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3 py-2.5 text-center">
                                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                    <p class="text-base font-bold {{ $vc }} mt-0.5 tabular-nums">{{ $v }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Status',    ucfirst($viewing->status)],
                            ['Audience',  str_replace('_',' ',ucfirst($viewing->audience))],
                            ['From',      $viewing->from_name ? $viewing->from_name.' <'.$viewing->from_email.'>' : $viewing->from_email ?? '-'],
                            ['Scheduled', $viewing->scheduled_at?->format('d M Y, H:i') ?? '-'],
                            ['Sent At',   $viewing->sent_at?->format('d M Y, H:i') ?? '-'],
                            ['Created',   $viewing->created_at->format('d M Y')],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->preview_text)
                        <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Preview Text</p>
                            <p class="text-sm text-primary-500 italic">{{ $viewing->preview_text }}</p>
                        </div>
                    @endif
                    {{-- Body preview --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">Email Body Preview</p>
                        <div class="rounded-xl border border-primary-100 overflow-hidden" style="max-height:400px;overflow-y:auto;">
                            <iframe srcdoc="{{ $viewing->html_body }}" class="w-full" style="min-height:300px;border:none;"
                                    sandbox="allow-same-origin"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ COMPOSE MODAL ═══ --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal',false)"></div>
            <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">

                {{-- Step indicator --}}
                <div class="flex items-center gap-0 px-6 py-5 border-b border-primary-100">
                    @foreach(['Details','Content','Audience','Review'] as $i => $label)
                        @php $n=$i+1; $done=$activeStep>$n; $active=$activeStep===$n; @endphp
                        <div class="flex items-center {{ $n < 4 ? 'flex-1' : '' }}">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border-2 transition
                                            {{ $done ? 'bg-success-500 border-success-500 text-white' : ($active ? 'bg-primary-500 border-primary-500 text-white' : 'bg-white border-primary-200 text-primary-300') }}">
                                    {{ $done ? '✓' : $n }}
                                </div>
                                <span class="text-xs font-semibold {{ $active ? 'text-primary-600' : ($done ? 'text-success-600' : 'text-primary-300') }} hidden sm:block">{{ $label }}</span>
                            </div>
                            @if($n < 4)<div class="flex-1 h-0.5 mx-2 {{ $done ? 'bg-success-400' : 'bg-primary-100' }}"></div>@endif
                        </div>
                    @endforeach
                    <button wire:click="$set('showModal',false)" class="ml-4 w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition flex-shrink-0">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="px-6 py-5 space-y-4">
                    {{-- Step 1: Details --}}
                    @if($activeStep === 1)
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Campaign Name <span class="text-danger-500">*</span></label>
                                <input wire:model="campaignName" type="text" placeholder="e.g. Christmas Sale 2025"
                                       class="w-full border {{ $errors->has('campaignName') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                @error('campaignName')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Subject Line <span class="text-danger-500">*</span></label>
                                <input wire:model="subject" type="text" placeholder="e.g. 🎄 Up to 30% off this Christmas"
                                       class="w-full border {{ $errors->has('subject') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                @error('subject')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Preview Text <span class="font-normal normal-case text-primary-200">(shown in inbox preview)</span></label>
                                <input wire:model="previewText" type="text" placeholder="e.g. Don't miss our biggest sale of the year…"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">From Name</label>
                                <input wire:model="fromName" type="text" placeholder="Bethany House"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">From Email</label>
                                <input wire:model="fromEmail" type="email"
                                       class="w-full border {{ $errors->has('fromEmail') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                @error('fromEmail')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Reply-To Email <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                                <input wire:model="replyTo" type="email"
                                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                        </div>
                    @endif

                    {{-- Step 2: Content --}}
                    @if($activeStep === 2)
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">HTML Body <span class="text-danger-500">*</span></label>
                            <textarea wire:model="htmlBody" rows="16" placeholder="Paste your HTML email template here…"
                                      class="w-full border {{ $errors->has('htmlBody') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 font-mono placeholder:font-sans placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-y"></textarea>
                            @error('htmlBody')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Plain-text version <span class="font-normal normal-case text-primary-200">(optional, for accessibility)</span></label>
                            <textarea wire:model="plainBody" rows="6"
                                      class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-y"></textarea>
                        </div>
                    @endif

                    {{-- Step 3: Audience --}}
                    @if($activeStep === 3)
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Recipient Audience <span class="text-danger-500">*</span></label>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach(['all_customers'=>['All Customers','bi-people'],'active'=>['Active Customers','bi-person-check'],'business'=>['Business','bi-building'],'individual'=>['Individual','bi-person']] as $v=>[$l,$icon])
                                    <label class="flex items-center gap-3 rounded-xl border px-4 py-3 cursor-pointer transition
                                                  {{ $audience===$v ? 'border-primary-400 bg-primary-50 ring-1 ring-primary-300' : 'border-primary-100 hover:border-primary-300' }}">
                                        <input type="radio" wire:model.live="audience" value="{{ $v }}" class="text-primary-500 focus:ring-primary-400 border-primary-200">
                                        <div>
                                            <i class="bi {{ $icon }} text-primary-400 text-sm mr-1"></i>
                                            <span class="text-sm font-semibold text-primary-600">{{ $l }}</span>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Schedule Send <span class="font-normal normal-case text-primary-200">(leave blank to save as draft)</span></label>
                            <input wire:model="scheduledAt" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('scheduledAt')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    {{-- Step 4: Review --}}
                    @if($activeStep === 4)
                        <div class="space-y-4">
                            <p class="text-sm font-bold text-primary-500">Review & Confirm</p>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                @foreach([
                                    ['Campaign',  $campaignName],
                                    ['Subject',   $subject],
                                    ['From',      $fromName ? "$fromName <$fromEmail>" : ($fromEmail ?: '-')],
                                    ['Audience',  str_replace('_',' ',ucfirst($audience))],
                                    ['Schedule',  $scheduledAt ?: 'Draft (not scheduled)'],
                                ] as [$l,$v])
                                    <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                        <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                        <p class="text-sm font-semibold text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                                    </div>
                                @endforeach
                            </div>
                            @if($htmlBody)
                                <div class="rounded-xl border border-primary-100 overflow-hidden">
                                    <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide px-4 py-2.5 bg-primary-50/50 border-b border-primary-100">Email Preview</p>
                                    <div style="max-height:250px;overflow-y:auto;">
                                        <iframe srcdoc="{{ $htmlBody }}" class="w-full" style="min-height:200px;border:none;"
                                                sandbox="allow-same-origin"></iframe>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Navigation --}}
                <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="prevStep" @if($activeStep===1) disabled @endif
                            class="inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-white px-4 py-2 text-sm font-semibold text-primary-400 hover:text-primary-600 transition disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                    @if($activeStep < 4)
                        <button wire:click="nextStep"
                                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
                            Continue <i class="bi bi-arrow-right"></i>
                        </button>
                    @else
                        <div class="flex items-center gap-2">
                            <button wire:click="save('draft')" wire:loading.attr="disabled"
                                    class="rounded-xl border border-primary-200 bg-white px-4 py-2 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition disabled:opacity-60">
                                <span wire:loading.remove wire:target="save('draft')">Save Draft</span>
                                <span wire:loading wire:target="save('draft')">Saving…</span>
                            </button>
                            <button wire:click="save('scheduled')" wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                                <span wire:loading.remove wire:target="save('scheduled')"><i class="bi bi-send mr-1"></i>{{ $scheduledAt ? 'Schedule' : 'Save Campaign' }}</span>
                                <span wire:loading wire:target="save('scheduled')">Saving…</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ DELETE CONFIRM ═══ --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showDeleteModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showDeleteModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-6 text-center space-y-3">
                    <div class="w-14 h-14 rounded-full bg-danger-50 border border-danger-200 flex items-center justify-center mx-auto">
                        <i class="bi bi-envelope-x text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Campaign?</h2>
                    <p class="text-sm text-primary-400"><span class="font-semibold text-primary-600">{{ $deletingName }}</span> will be permanently removed.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 transition">Cancel</button>
                    <button wire:click="delete" wire:loading.attr="disabled"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger-500 hover:bg-danger-600 px-4 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-danger-500/20">
                        <span wire:loading.remove wire:target="delete">Delete</span>
                        <span wire:loading wire:target="delete">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>