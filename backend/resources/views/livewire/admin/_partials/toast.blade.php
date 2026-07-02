{{--
    Reusable toast notification - include in every Livewire page that dispatches 'show-toast'.
    Reads $wire.toastMessage and $wire.toastType (success | error | warning | info).
--}}
<div
    x-data="{ show: false, message: '', type: 'success' }"
    x-on:show-toast.window="
        message = $wire.toastMessage;
        type    = $wire.toastType;
        show    = true;
        setTimeout(() => show = false, 3800)
    "
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    x-cloak
    class="fixed bottom-6 right-6 z-[100] flex max-w-sm items-start gap-3 rounded-xl border px-4 py-3.5 shadow-lg"
    :class="{
        'border-success-200 bg-success-50 text-success-700': type === 'success',
        'border-danger-200 bg-danger-50 text-danger-700':   type === 'error',
        'border-warning-200 bg-warning-50 text-warning-700': type === 'warning',
        'border-info-200 bg-info-50 text-info-700':         type === 'info',
    }"
>
    <i class="mt-0.5 shrink-0 text-base"
       :class="{
           'ri-checkbox-circle-fill text-success-500': type === 'success',
           'ri-close-circle-fill text-danger-500':     type === 'error',
           'ri-alert-fill text-warning-500':           type === 'warning',
           'ri-information-fill text-info-500':        type === 'info',
       }"></i>
    <span class="text-sm font-medium" x-text="message"></span>
    <button @click="show = false"
            class="ml-auto flex size-5 shrink-0 items-center justify-center rounded opacity-60 hover:opacity-100 transition">
        <i class="ri-close-line text-xs"></i>
    </button>
</div>