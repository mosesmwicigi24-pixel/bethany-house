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
}
