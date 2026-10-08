<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A customer's phone, as staff type it: a real number or nothing.
 *
 * The till's phone box was the only free-text field on a sale — the till had
 * no order note and the New Customer form made the phone required — so notes
 * went into it: "cash", "refer to iand m", "ATC Measurements" (51 orders and 65
 * customer records on 2026-10-01). Every report reads the phone through
 * normalize_phone(), which correctly refuses a note, so each of those sales
 * lost its buyer. This stops it at entry, on the same definition the reports
 * use (Phone::e164 mirrors the SQL), and is stricter only where the SQL says
 * write-time must be: length limits, a bare 9-digit number must look like a
 * Kenyan mobile, and an obvious placeholder (0700000000) is not a number.
 *
 * Two or three numbers in one field ("0722 000 000 / 0733 111 111" — a couple,
 * an office line) is established practice here; each must be a real number.
 *
 * The value is checked, never rewritten: what staff typed is stored as typed,
 * because M-Pesa prompts and the storefront lookup read it in that shape.
 *
 * $alreadyStored: values already on file that may be submitted again without
 * passing — the attached customer's own phone (the till copies it onto the
 * sale) and the order's current phone on an edit. 65 customer records held a
 * note on 2026-10-01; checking those would block every sale to them, a worse
 * failure than the one being fixed. Only a NEWLY typed phone must pass.
 */
class CustomerPhone implements ValidationRule
{
    /** @var string[] */
    private array $alreadyStored;

    public function __construct(string|array|null $alreadyStored = null)
    {
        $this->alreadyStored = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v), (array) $alreadyStored), fn ($v) => $v !== ''));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;                                   // empty is allowed; `required` decides
        }
        $raw = trim((string) $value);
        if (in_array($raw, $this->alreadyStored, true)) {
            return;                                   // already on file, not newly typed
        }

        if (preg_match('/[A-Za-z]/', $raw)) {
            $fail('That looks like a note, not a phone number. Put notes in the order note, and enter the customer\'s phone here — or leave it empty if they have none.');
            return;
        }
        if (preg_match('/[^0-9+\-\s().\/,]/', $raw)) {
            $fail('A phone number can only contain digits, spaces and + - ( ). Separate two numbers with /.');
            return;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[\/,]/', $raw)), fn ($p) => $p !== ''));
        if (count($parts) > 3) {
            $fail('That is more numbers than one field should hold — keep up to three, separated by /.');
            return;
        }
        foreach ($parts as $part) {
            if ($message = self::problemWith($part)) {
                $fail(count($parts) > 1 ? "{$part}: {$message}" : $message);
                return;
            }
        }
    }

    /** Why one number is not a real phone, or null when it is. */
    private static function problemWith(string $raw): ?string
    {
        $e164 = Phone::e164($raw);
        $digitsTyped = preg_replace('/\D/', '', $raw);
        if ($e164 === null) {
            return 'That is not a full phone number. Enter every digit — for a number outside Kenya, start with + and the country code (e.g. +256 772 123 456).';
        }
        $digits = substr($e164, 1);
        if (strlen($digits) > 15) {
            return 'That is too long for a phone number.';
        }
        if (str_starts_with($digits, '254') && strlen($digits) !== 12) {
            return 'A Kenyan number has 9 digits after the 0 — like 0722 123 456.';
        }
        // A bare 9 digits is read as Kenyan; it must then look like a Kenyan
        // mobile (7…, 10…, 11…). Anything else — "123456789" — is as likely a
        // reference number as a phone.
        if (! str_contains($raw, '+') && strlen($digitsTyped) === 9 && ! preg_match('/^(7|1[01])/', $digitsTyped)) {
            return 'That is not a full phone number. Enter it with the leading 0 (0722 123 456), or with + and the country code.';
        }
        if (self::isPlaceholder($digits)) {
            return 'That looks like a placeholder, not the customer\'s number. Leave the phone empty if they have none.';
        }

        return null;
    }

    /**
     * Fillers, not numbers: a Kenyan number whose last 8 digits are one digit
     * repeated or the classic 12345678 / 87654321 (0700000000, 0711111111,
     * 0712345678); a foreign number whose last 7 digits are one digit repeated.
     * Deliberately narrow — a straight run elsewhere (+263 77 123 4567,
     * 0723 456 789) is a plausible real number and must pass.
     */
    public static function isPlaceholder(string $digits): bool
    {
        if (str_starts_with($digits, '254')) {
            $tail = substr($digits, 4);

            return preg_match('/^(\d)\1{7}$/', $tail) === 1 || in_array($tail, ['12345678', '87654321'], true);
        }

        return preg_match('/(\d)\1{6}$/', $digits) === 1;
    }
}
