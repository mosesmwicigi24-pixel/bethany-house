<div class="max-w-3xl mx-auto space-y-6 font-dm-sans">

    {{-- Header --}}
    <div>
        <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Bulk Import</h1>
        <p class="text-sm text-gray-500 mt-0.5">Import products in bulk via CSV file</p>
    </div>

    {{-- Flash --}}
    @if($flashMessage)
    <div class="rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'error' ? 'bg-danger-50 text-danger-700 border-danger-200' : 'bg-success-50 text-success-700 border-success-200' }}">
        {{ $flashMessage }}
    </div>
    @endif

    {{-- Step 1: Template --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-2xl bg-primary-100 flex items-center justify-center flex-shrink-0">
                <span class="text-primary-700 font-bold text-sm">1</span>
            </div>
            <div class="flex-1">
                <h2 class="font-semibold text-gray-900">Download the Template</h2>
                <p class="text-sm text-gray-500 mt-1 mb-4">
                    Use our CSV template to structure your data correctly.
                    <strong class="text-gray-700">Required</strong> columns must be present.
                </p>

                <div class="overflow-x-auto rounded-xl border border-gray-100 mb-4">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Column</th>
                                <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Required</th>
                                <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Example</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($this->columns as [$col, $req, $example])
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-3 py-2 font-mono font-medium text-gray-700">{{ $col }}</td>
                                <td class="px-3 py-2">
                                    @if($req)
                                        <span class="inline-flex px-1.5 py-0.5 rounded-lg text-xs bg-danger-100 text-danger-600 font-semibold">Required</span>
                                    @else
                                        <span class="inline-flex px-1.5 py-0.5 rounded-lg text-xs bg-gray-100 text-gray-500">Optional</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-500">{{ $example }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <a href="{{ route('admin.products.import.template') }}"
                   class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm">
                    <i class="bi bi-download"></i> Download CSV Template
                </a>
            </div>
        </div>
    </div>

    {{-- Step 2: Upload --}}
    @if($step !== 'done')
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-2xl bg-primary-100 flex items-center justify-center flex-shrink-0">
                <span class="text-primary-700 font-bold text-sm">2</span>
            </div>
            <div class="flex-1 space-y-4">
                <div>
                    <h2 class="font-semibold text-gray-900">Upload Your CSV</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        CSV only · max 10 MB ·
                        Existing SKUs will be <strong class="text-gray-700">updated</strong>;
                        new SKUs will be <strong class="text-gray-700">created</strong>.
                    </p>
                </div>

                {{-- Dropzone --}}
                @if(!$csvFile)
                <label for="csvUpload"
                    class="block border-2 border-dashed border-gray-200 rounded-2xl p-10 text-center cursor-pointer hover:border-primary-300 hover:bg-primary-50/20 transition-all">
                    <i class="bi bi-cloud-arrow-up text-5xl text-gray-200"></i>
                    <p class="text-gray-500 mt-3 text-sm font-medium">
                        Drop your CSV here or <span class="text-primary-600 font-semibold">click to browse</span>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">.csv files only · max 10 MB</p>
                    <input type="file" id="csvUpload" wire:model="csvFile" accept=".csv,text/csv" class="hidden">
                </label>
                @else

                {{-- File selected --}}
                <div class="flex items-center gap-3 bg-primary-50 border border-primary-200 rounded-2xl px-4 py-3.5">
                    <div class="w-10 h-10 rounded-xl bg-primary-100 flex items-center justify-center flex-shrink-0">
                        <i class="bi bi-file-earmark-spreadsheet text-primary-600 text-lg"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-gray-800 truncate">{{ $csvFile->getClientOriginalName() }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">{{ number_format($csvFile->getSize() / 1024, 1) }} KB</div>
                    </div>
                    <button wire:click="removeCsvFile"
                        class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-danger-500 hover:bg-danger-50 transition-colors">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                {{-- Options --}}
                <div class="pt-2 border-t border-gray-100 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-700">Import Options</h3>
                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-gray-200 hover:border-primary-200 hover:bg-primary-50/30 transition-all">
                        <input wire:model="dryRun" type="checkbox"
                            class="mt-0.5 rounded border-gray-300 text-primary-500 focus:ring-primary-400">
                        <div>
                            <div class="text-sm font-semibold text-gray-700">Dry run (preview only)</div>
                            <div class="text-xs text-gray-400">Validates your CSV and shows what would be created/updated <strong>without</strong> saving anything.</div>
                        </div>
                    </label>
                </div>

                {{-- Import button --}}
                <button wire:click="import" wire:loading.attr="disabled"
                    class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white text-sm font-bold rounded-2xl transition-colors shadow-sm disabled:opacity-60 flex items-center justify-center gap-2">
                    <span wire:loading.remove wire:target="import">
                        <i class="bi bi-lightning-charge-fill me-1"></i>
                        {{ $dryRun ? 'Preview Import' : 'Start Import' }}
                    </span>
                    <span wire:loading wire:target="import" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ $dryRun ? 'Previewing…' : 'Importing…' }}
                    </span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Step 3: Results --}}
    @if($step === 'done')
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-6 space-y-5">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-2xl bg-success-100 flex items-center justify-center flex-shrink-0">
                <i class="bi bi-check-lg text-success-600 text-lg"></i>
            </div>
            <div>
                <h2 class="font-semibold text-gray-900">
                    {{ $dryRun ? 'Dry Run Complete' : 'Import Complete' }}
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    @if($dryRun)
                        Preview only - no data was saved.
                    @else
                        {{ $resultCreated + $resultUpdated }} products processed.
                    @endif
                </p>
            </div>
        </div>

        {{-- Counters --}}
        <div class="grid grid-cols-3 gap-3">
            <div class="text-center bg-success-50 rounded-2xl p-4 ring-1 ring-success-100">
                <div class="text-3xl font-bold text-success-600">{{ $resultCreated }}</div>
                <div class="text-xs text-gray-500 mt-1 font-medium">{{ $dryRun ? 'Would Create' : 'Created' }}</div>
            </div>
            <div class="text-center bg-primary-50 rounded-2xl p-4 ring-1 ring-primary-100">
                <div class="text-3xl font-bold text-primary-600">{{ $resultUpdated }}</div>
                <div class="text-xs text-gray-500 mt-1 font-medium">{{ $dryRun ? 'Would Update' : 'Updated' }}</div>
            </div>
            <div class="text-center bg-danger-50 rounded-2xl p-4 ring-1 ring-danger-100">
                <div class="text-3xl font-bold text-danger-600">{{ count($resultFailed) }}</div>
                <div class="text-xs text-gray-500 mt-1 font-medium">Failed</div>
            </div>
        </div>

        {{-- Failed rows --}}
        @if(!empty($resultFailed))
        <div>
            <h3 class="text-sm font-semibold text-gray-700 mb-2">
                <i class="bi bi-exclamation-triangle text-danger-500 me-1"></i> Failed Rows
            </h3>
            <div class="rounded-xl overflow-hidden border border-danger-100">
                <table class="w-full text-sm">
                    <thead class="bg-danger-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-danger-700">Row</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-danger-700">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-danger-50">
                        @foreach($resultFailed as $fail)
                        <tr>
                            <td class="px-4 py-2.5 text-danger-700 font-semibold tabular-nums">Row {{ $fail['row'] }}</td>
                            <td class="px-4 py-2.5 text-gray-600">{{ $fail['reason'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <div class="flex flex-wrap gap-3 pt-2 border-t border-gray-100">
            @if(!$dryRun)
            <a href="{{ route('admin.products.index') }}"
               class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors">
                <i class="bi bi-grid"></i> View Products
            </a>
            @endif
            <button wire:click="removeCsvFile"
                class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 text-sm font-semibold px-4 py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-counterclockwise"></i> {{ $dryRun ? 'Run for Real' : 'Import Another File' }}
            </button>
        </div>
    </div>
    @endif

</div>