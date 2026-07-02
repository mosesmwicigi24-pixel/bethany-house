<div class="max-w-3xl mx-auto space-y-6">

    <div>
        <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
            <i class="bi bi-truck"></i><span>Procurement</span>
            <i class="bi bi-chevron-right text-[10px]"></i>
            <a href="{{ route('admin.procurement.suppliers') }}" class="hover:text-primary-500">Suppliers</a>
            <i class="bi bi-chevron-right text-[10px]"></i>
            <span>{{ $isEditing ? 'Edit' : 'Add' }} Supplier</span>
        </div>
        <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">
            {{ $isEditing ? 'Edit '.$supplier->name : 'New Supplier' }}
        </h1>
    </div>

    @if($errors->any())
        <div class="flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
            <ul class="space-y-0.5 list-disc list-inside">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Code <span class="text-danger-500">*</span></label>
                <input wire:model="code" type="text" placeholder="e.g. SUP-001"
                       class="w-full border {{ $errors->has('code') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 uppercase focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                @error('code')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supplier Name <span class="text-danger-500">*</span></label>
                <input wire:model="name" type="text" placeholder="e.g. Acme Fabrics Ltd"
                       class="w-full border {{ $errors->has('name') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                @error('name')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Contact Person</label>
                <input wire:model="contactPerson" type="text"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Email</label>
                <input wire:model="email" type="email"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                @error('email')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Phone</label>
                <input wire:model="phone" type="text"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Tax ID / VAT Number</label>
                <input wire:model="taxId" type="text"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
        </div>

        {{-- Address section --}}
        <div class="pt-4 border-t border-primary-50">
            <p class="text-xs font-semibold text-primary-400 uppercase tracking-wide mb-3">Address</p>
            <div class="space-y-3">
                <input wire:model="addressLine1" type="text" placeholder="Address line 1"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                <input wire:model="addressLine2" type="text" placeholder="Address line 2 (optional)"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <input wire:model="city" type="text" placeholder="City"
                           class="col-span-2 border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    <input wire:model="postalCode" type="text" placeholder="Postal"
                           class="border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    <input wire:model="countryCode" type="text" maxlength="2" placeholder="KE"
                           class="border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 uppercase placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                </div>
            </div>
        </div>

        {{-- Terms and rating --}}
        <div class="pt-4 border-t border-primary-50 grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Payment Terms</label>
                <input wire:model="paymentTerms" type="text" placeholder="e.g. Net 30"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Rating (0–5)</label>
                <input wire:model="rating" type="number" min="0" max="5" step="0.1" placeholder="4.5"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                @error('rating')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
            <textarea wire:model="notes" rows="3"
                      class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
        </div>

        <label class="flex items-center gap-2.5 cursor-pointer">
            <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
            <span class="text-sm font-medium text-primary-500">Active supplier</span>
        </label>
    </div>

    <div class="flex items-center gap-3">
        <a href="{{ route('admin.procurement.suppliers') }}"
           class="rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
            Cancel
        </a>
        <button wire:click="save" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Supplier' : 'Create Supplier' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

</div>