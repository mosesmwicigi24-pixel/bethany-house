<?php

namespace App\Support;

/**
 * The one rulebook for hiding customer contact details from someone who may
 * see the figures but not the people's phone numbers and emails.
 *
 * Two layers, because contacts arrive in two shapes:
 *  1. STRUCTURED — a value under a key that names a contact (phone,
 *     customer_email, whatsapp…). Nulled. Only strings: a numeric field whose
 *     name happens to contain "phone" (a count) is a figure, not a contact.
 *  2. FREE TEXT — a drill-down's `who` ("+254711000111"), an attention item's
 *     `detail` ("call 0733 000 333"). Any phone- or email-shaped run inside
 *     any string is masked, whatever the key. This is the layer that catches
 *     the field nobody thought to name.
 */
final class CustomerContacts
{
    private const CONTACT_KEY = '/(phone|e_?mail|whatsapp|msisdn|mobile)/i';

    /**
     * Keys that carry a buyer's IDENTITY — a customer id when there is a
     * record, the raw phone when there is not (MetricEngine::CUSTOMER_KEY;
     * the drill-downs' `who`). Measured on production (cycle 9): 116 of 1,494
     * phones are digits in no shape a free-text pattern can safely claim —
     * Nigerian 234…, South African 27…, Fijian, typed without a plus. Under
     * these keys a number-shaped value that is not a short record id is a
     * phone, whatever its country, so it is tokenised.
     */
    private const IDENTITY_KEY = '/^(ckey|who|buyer_key|customer_key)$/i';

    private const PHONE_LIKE = '/^\+?\d[\d \-]{6,}\d$/';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * Phone-shaped runs: an international number with a plus; a Kenyan mobile
     * or landline (07…, 01…, 2547…, 2541…), optionally spaced; or a bare
     * country-coded 12-digit number (Zambia 260…, Tanzania 255… — the
     * corridors this business ships to). Amounts never take these shapes:
     * money is stored with decimals and no leading zero.
     */
    private const PHONE = '/(?<![\w.])(?:\+\d[\d ]{8,16}\d|(?:254|0)[17]\d{2} ?\d{3} ?\d{3}|2\d{2}\d{9})(?![\w])/';

    /**
     * Each contact becomes a token that is UNIQUE per value and not reversible.
     *
     * Not a flat "[hidden]": the engine keys a walk-in buyer by their raw phone
     * (MetricEngine::CUSTOMER_KEY falls back to customer_phone), so flattening
     * would make every walk-in the same buyer on screen — rows collapse, React
     * keys collide. Not a plain hash either: a phone number's space is small
     * enough to brute-force an unkeyed hash in seconds. An HMAC under the
     * app key keeps rows distinct and the number secret.
     */
    public static function maskText(string $s): string
    {
        return preg_replace_callback([self::EMAIL, self::PHONE], fn ($m) => self::token($m[0]), $s) ?? $s;
    }

    private static function token(string $contact): string
    {
        return '[hidden-' . substr(hash_hmac('sha256', $contact, (string) config('app.key')), 0, 8) . ']';
    }

    /** Walk any decoded JSON structure. */
    public static function redact(mixed $data, ?string $key = null): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : $key);
            }

            return $out;
        }

        if (is_string($data)) {
            if ($key !== null && preg_match(self::CONTACT_KEY, $key) && $data !== '') {
                return null;
            }
            if ($key !== null && preg_match(self::IDENTITY_KEY, $key) && preg_match(self::PHONE_LIKE, $data)) {
                return self::token($data);
            }

            return self::maskText($data);
        }

        return $data;
    }

    /** Same rules for one row of an object/array (PDF data, collections). */
    public static function redactRow(object|array $row): object|array
    {
        $isObject = is_object($row);
        $out      = self::redact((array) $row);

        return $isObject ? (object) $out : $out;
    }

    /** Blank contact columns by header, then mask contact-shaped text in every cell. */
    public static function redactCsv(string $csv): string
    {
        $bom = str_starts_with($csv, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $in  = fopen('php://temp', 'r+');
        fwrite($in, substr($csv, strlen($bom)));
        rewind($in);

        $out    = fopen('php://temp', 'r+');
        fwrite($out, $bom);
        $hidden = [];
        $first  = true;

        while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
            if ($first) {
                foreach ($row as $i => $header) {
                    if (preg_match(self::CONTACT_KEY, (string) $header)) {
                        $hidden[] = $i;
                    }
                }
                $first = false;
                fputcsv($out, $row, ',', '"', '');
                continue;
            }
            foreach ($row as $i => $cell) {
                $row[$i] = in_array($i, $hidden, true) && $cell !== '' ? self::token((string) $cell) : self::maskText((string) $cell);
            }
            fputcsv($out, $row, ',', '"', '');
        }

        rewind($out);
        $result = stream_get_contents($out);
        fclose($in);
        fclose($out);

        return $result;
    }

    // ══ Phase 4A: masking by role, outside Reports (plan §9) ═════════════════
    //
    // Reports keep their own rule above (contacts need customers.view, tokens
    // for identity keys). Everywhere else a customer's contacts reach a
    // browser, this decides what the caller's ROLES allow:
    //
    //   phone/email  full    super_admin, admin, finance_manager, outlet_manager
    //                masked  accountant, pos_clerk   (07••••1853, n•••82@gmail.com)
    //                omitted procurement_*, tailor, system_admin
    //   address      masked  finance_manager, pos_clerk, accountant
    //                omitted wherever contacts are omitted
    //
    // A role this table does not name (one made in the Roles screen) is
    // masked: a new role sees less until someone decides it should see more.

    public const FULL    = 'full';
    public const MASKED  = 'masked';
    public const OMITTED = 'omitted';

    /** The mask character. Never typed by a person, so a value containing it is a mask. */
    public const MASK_CHAR = '•';

    /** role => [contacts, address] */
    public const ROLE_POLICY = [
        'super_admin'         => [self::FULL, self::FULL],
        'admin'               => [self::FULL, self::FULL],
        'finance_manager'     => [self::FULL, self::MASKED],
        'outlet_manager'      => [self::FULL, self::FULL],
        'accountant'          => [self::MASKED, self::MASKED],
        'pos_clerk'           => [self::MASKED, self::MASKED],
        'procurement_officer' => [self::OMITTED, self::OMITTED],
        'procurement_manager' => [self::OMITTED, self::OMITTED],
        'tailor'              => [self::OMITTED, self::OMITTED],
        'system_admin'        => [self::OMITTED, self::OMITTED],
    ];

    private const RANK = [self::FULL => 0, self::MASKED => 1, self::OMITTED => 2];

    /** Keys whose value is a delivery/billing address line. City and country stay. */
    private const ADDRESS_KEY = '/^(?:(?:shipping|billing|delivery)_)?(?:address(?:_line_?\d)?|address_line_?\d|street|postal_code|delivery_instructions)$/i';

    /**
     * Sub-trees that are not the customer: the outlet a sale was made at, the
     * staff member who served it. Their phone and email are the business's.
     */
    private const NOT_CUSTOMER = [
        'outlet', 'pickup_outlet', 'from_outlet', 'to_outlet', 'fromOutlet', 'toOutlet',
        'creator', 'created_by_user', 'createdBy', 'approvedBy', 'approved_by_user', 'supplier',
    ];

    /**
     * What this user may receive of a customer's contacts on a surface read
     * through $permissions (e.g. 'orders.view'). Strictest among the roles
     * that grant ANY of those permissions — a second role that grants the
     * read cannot loosen a stricter one; a role that does not grant it has no
     * say. With no granting role, every role the user holds is considered;
     * with no role at all (a hand-granted login), masked.
     *
     * @param  string|string[]  $permissions
     * @return array{contacts: string, address: string}
     */
    public static function policyFor(?\App\Models\User $user, string|array $permissions): array
    {
        if ($user === null || $user->hasRole('super_admin')) {
            return ['contacts' => self::FULL, 'address' => self::FULL];
        }

        $permissions = (array) $permissions;
        $roles = $user->roles->filter(fn ($role) => $role->permissions->whereIn('name', $permissions)->isNotEmpty());
        if ($roles->isEmpty()) {
            $roles = $user->roles;
        }
        if ($roles->isEmpty()) {
            return ['contacts' => self::MASKED, 'address' => self::MASKED];
        }

        $contacts = self::FULL;
        $address  = self::FULL;
        foreach ($roles as $role) {
            [$c, $a] = self::ROLE_POLICY[$role->name] ?? [self::MASKED, self::MASKED];
            $contacts = self::RANK[$c] > self::RANK[$contacts] ? $c : $contacts;
            $address  = self::RANK[$a] > self::RANK[$address] ? $a : $address;
        }

        return ['contacts' => $contacts, 'address' => $address];
    }

    public static function isFull(array $policy): bool
    {
        return $policy['contacts'] === self::FULL && $policy['address'] === self::FULL;
    }

    /** 0712341853 → 07••••1853; +254712341853 → +2••••1853. Short junk → ••••. */
    public static function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        $chars = preg_split('//u', $phone, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($chars) < 7) {
            return str_repeat(self::MASK_CHAR, 4);
        }

        return implode('', array_slice($chars, 0, 2)) . str_repeat(self::MASK_CHAR, 4) . implode('', array_slice($chars, -4));
    }

    /** nancy82@gmail.com → n•••82@gmail.com; ab@x.io → a•••@x.io. */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return self::maskPhone($email);
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        $tail   = strlen($local) > 3 ? substr($local, -2) : '';

        return substr($local, 0, 1) . str_repeat(self::MASK_CHAR, 3) . $tail . $domain;
    }

    /** A contact value already masked (or partly typed over a mask). */
    public static function isMasked(?string $value): bool
    {
        return $value !== null && str_contains($value, self::MASK_CHAR);
    }

    private static function maskContact(string $value): string
    {
        return str_contains($value, '@') ? self::maskEmail($value) : self::maskPhone($value);
    }

    /**
     * Apply a policy to any decoded JSON structure, by key, recursively.
     * Contact-named keys are masked or nulled; address lines likewise; any
     * phone- or email-shaped run inside any other string is masked too (a
     * note that says "call 0712 341 853"). A FULL policy returns the data
     * untouched.
     *
     * @param  array{contacts: string, address: string}  $policy
     */
    public static function apply(mixed $data, array $policy, ?string $key = null): mixed
    {
        if (self::isFull($policy)) {
            return $data;
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && in_array($k, self::NOT_CUSTOMER, true)) {
                    $out[$k] = $v;
                    continue;
                }
                $out[$k] = self::apply($v, $policy, is_string($k) ? $k : $key);
            }

            return $out;
        }

        if (!is_string($data) || $data === '') {
            return $data;
        }

        if ($key !== null && preg_match(self::ADDRESS_KEY, $key)) {
            return match ($policy['address']) {
                self::FULL    => $data,
                self::OMITTED => null,
                default       => str_repeat(self::MASK_CHAR, 6),
            };
        }

        if ($policy['contacts'] === self::FULL) {
            return $data;
        }

        if ($key !== null && preg_match(self::CONTACT_KEY, $key) && !self::isMasked($data)) {
            return $policy['contacts'] === self::OMITTED ? null : self::maskContact($data);
        }

        // Free text: mask contact-shaped runs wherever they sit.
        return preg_replace_callback([self::EMAIL, self::PHONE], fn ($m) => $policy['contacts'] === self::OMITTED
            ? '[hidden]'
            : self::maskContact($m[0]), $data) ?? $data;
    }

    /** The same rules over a CSV: contact/address columns by header, free text in every cell. */
    public static function applyCsv(string $csv, array $policy): string
    {
        if (self::isFull($policy)) {
            return $csv;
        }

        $bom = str_starts_with($csv, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $in  = fopen('php://temp', 'r+');
        fwrite($in, substr($csv, strlen($bom)));
        rewind($in);
        $out = fopen('php://temp', 'r+');
        fwrite($out, $bom);

        $keys  = [];
        $first = true;
        while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
            if ($first) {
                // "Customer Phone" → customer_phone, so the key rules apply.
                $keys  = array_map(fn ($h) => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $h), '_')), $row);
                $first = false;
                fputcsv($out, $row, ',', '"', '');
                continue;
            }
            foreach ($row as $i => $cell) {
                $row[$i] = (string) self::apply((string) $cell, $policy, $keys[$i] ?? null);
            }
            fputcsv($out, $row, ',', '"', '');
        }

        rewind($out);
        $result = (string) stream_get_contents($out);
        fclose($in);
        fclose($out);

        return $result;
    }

    /**
     * A form filled from a masked screen sends the mask back. On a write,
     * replace a masked contact with the value on file when it is the mask OF
     * that value (nothing changed), and drop it otherwise, so the stored
     * number is never overwritten by bullets — nor the request refused for
     * them. $onFile maps request keys to the stored values.
     *
     * @param  array<string, ?string>  $onFile
     */
    public static function restoreMasked(\Illuminate\Http\Request $request, array $onFile): void
    {
        foreach ($onFile as $field => $stored) {
            $sent = $request->input($field);
            if (!is_string($sent) || !self::isMasked($sent)) {
                continue;
            }
            if ($stored !== null && $stored !== '' && self::maskContact($stored) === trim($sent)) {
                $request->merge([$field => $stored]);
            } else {
                $request->request->remove($field);
                $request->query->remove($field);
                if ($request->isJson()) {
                    $request->json()->remove($field);
                }
            }
        }
    }
}
