<?php

namespace App\Rules;

use App\Support\SellableCurrency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A currency the business may sell or buy in: active, with a reporting rate. */
class SellableCurrencyRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!SellableCurrency::isSellable((string) $value)) {
            $fail(SellableCurrency::reason((string) $value));
        }
    }
}
