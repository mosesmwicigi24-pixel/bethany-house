<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The phone book the business actually knows, shaped for Google Contacts
 * (owner, 2026-10-20).
 *
 * One row per phone number (E.164, so 0722… and +254722… are one person),
 * carrying the BEST name the hub holds for it, in this order of trust:
 *   1. the customer record's own first/last name;
 *   2. the name typed on that phone's most recent order (every WhatsApp, chat
 *      and guest order carries one);
 *   3. the name on the lead.
 * When none of those exists the Name is left EMPTY — never a made-up label
 * like "Bethany Contact 0404" — and the Notes say "No name on file", so the
 * phone shows the number and the gap is visible and fillable. Every row joins
 * the group "Bethany House" so the set can be found, filtered or removed in
 * Google in one move.
 *
 * Read-only. The caller decides who may take it (CustomerController).
 */
final class GoogleContactsCsv
{
    public const GROUP = 'Bethany House';

    /** The Google Contacts import columns, in Google's own order. */
    public const HEADERS = [
        'Name', 'Given Name', 'Family Name', 'Phone 1 - Type', 'Phone 1 - Value',
        'E-mail 1 - Type', 'E-mail 1 - Value', 'Notes', 'Group Membership',
    ];

    /**
     * @return array{rows: list<array<string, string>>, stats: array{contacts:int, named:int, unnamed:int, from_customer:int, from_order:int, from_lead:int, merged_spellings:int, skipped_no_phone:int}}
     */
    public static function build(): array
    {
        $byPhone = [];      // e164 => ['first','last','email','source','customer_number','orders','last_order_at','raw' => set]
        $skipped = 0;

        $put = function (string $source, ?string $rawPhone, ?string $first, ?string $last, ?string $email, array $extra = []) use (&$byPhone, &$skipped): void {
            $e164 = Phone::e164($rawPhone);
            if ($e164 === null) {
                $skipped++;

                return;
            }
            $first = self::clean($first);
            $last  = self::clean($last);
            $row   = $byPhone[$e164] ?? ['first' => '', 'last' => '', 'email' => '', 'source' => '', 'customer_number' => '', 'orders' => 0, 'last_order_at' => null, 'raw' => []];
            $row['raw'][$rawPhone] = true;
            // A name wins only over a weaker source (customer > order > lead) or over nothing.
            $rank  = ['customer' => 3, 'order' => 2, 'lead' => 1];
            if (($first !== '' || $last !== '') && ($row['first'] === '' && $row['last'] === '' || $rank[$source] > ($rank[$row['source']] ?? 0))) {
                $row['first']  = $first;
                $row['last']   = $last;
                $row['source'] = $source;
            }
            if ($row['email'] === '' && $email && str_contains($email, '@') && !str_ends_with($email, '.local')) {
                $row['email'] = strtolower(trim($email));
            }
            foreach ($extra as $k => $v) {
                if ($k === 'orders') {
                    $row['orders'] += (int) $v;
                } elseif ($k === 'last_order_at') {
                    if ($v && ($row['last_order_at'] === null || $v > $row['last_order_at'])) {
                        $row['last_order_at'] = $v;
                    }
                } elseif ($row[$k] === '' && $v) {
                    $row[$k] = (string) $v;
                }
            }
            $byPhone[$e164] = $row;
        };

        // 1. Customers, with their order count and last order date.
        DB::table('customers as c')
            ->leftJoinSub(
                DB::table('orders')->selectRaw('customer_id, COUNT(*) AS n, MAX(created_at) AS last_at')->whereNotNull('customer_id')->groupBy('customer_id'),
                'o', 'o.customer_id', '=', 'c.id',
            )
            ->whereNotNull('c.phone')
            ->orderBy('c.id')
            ->get(['c.phone', 'c.first_name', 'c.last_name', 'c.email', 'c.customer_number', 'o.n', 'o.last_at'])
            ->each(fn ($c) => $put('customer', $c->phone, $c->first_name, $c->last_name, $c->email,
                ['customer_number' => $c->customer_number, 'orders' => (int) ($c->n ?? 0), 'last_order_at' => $c->last_at]));

        // 2. The name typed on each phone's most recent order (guests and
        //    customers whose record is blank alike).
        DB::table('orders')
            ->whereNotNull('customer_phone')
            ->orderByDesc('created_at')
            ->get(['customer_phone', 'customer_first_name', 'customer_last_name', 'customer_email', 'customer_id', 'created_at'])
            ->groupBy(fn ($o) => Phone::e164($o->customer_phone) ?? '')
            ->each(function (Collection $orders, string $e164) use ($put) {
                if ($e164 === '') {
                    return;
                }
                $latest = $orders->first();
                $named  = $orders->first(fn ($o) => self::clean($o->customer_first_name) !== '' || self::clean($o->customer_last_name) !== '') ?? $latest;
                $put('order', $latest->customer_phone, $named->customer_first_name, $named->customer_last_name, $latest->customer_email,
                    ['orders' => $orders->whereNull('customer_id')->count(), 'last_order_at' => $latest->created_at]);
            });

        // 3. Leads: the one guaranteed contact, a name when given.
        DB::table('leads')->whereNotNull('phone')->orderBy('id')->get(['phone', 'name', 'email'])
            ->each(function ($l) use ($put) {
                [$first, $last] = self::split($l->name);
                $put('lead', $l->phone, $first, $last, $l->email);
            });

        $rows  = [];
        $stats = ['contacts' => 0, 'named' => 0, 'unnamed' => 0, 'from_customer' => 0, 'from_order' => 0, 'from_lead' => 0, 'merged_spellings' => 0, 'skipped_no_phone' => $skipped];
        ksort($byPhone);
        foreach ($byPhone as $e164 => $r) {
            $name = trim($r['first'] . ' ' . $r['last']);
            $notes = [];
            if ($name === '') {
                $notes[] = 'No name on file';
            }
            if ($r['customer_number'] !== '') {
                $notes[] = 'Customer ' . $r['customer_number'];
            }
            if ($r['orders'] > 0) {
                $notes[] = $r['orders'] . ' order' . ($r['orders'] === 1 ? '' : 's');
            }
            if ($r['last_order_at']) {
                $notes[] = 'Last order ' . substr((string) $r['last_order_at'], 0, 10);
            }
            $rows[] = [
                'Name'             => $name,
                'Given Name'       => $r['first'],
                'Family Name'      => $r['last'],
                'Phone 1 - Type'   => 'Mobile',
                'Phone 1 - Value'  => $e164,
                'E-mail 1 - Type'  => $r['email'] !== '' ? 'Other' : '',
                'E-mail 1 - Value' => $r['email'],
                'Notes'            => implode(' · ', $notes),
                'Group Membership' => self::GROUP . ' ::: * myContacts',
            ];
            $stats['contacts']++;
            $name === '' ? $stats['unnamed']++ : $stats['named']++;
            if ($r['source'] !== '') {
                $stats['from_' . $r['source']]++;
            }
            if (count($r['raw']) > 1) {
                $stats['merged_spellings']++;
            }
        }

        return ['rows' => $rows, 'stats' => $stats];
    }

    /** @param list<array<string, string>> $rows */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADERS);
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($h) => self::safeCell($r[$h] ?? ''), self::HEADERS));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A cell that begins like a formula is prefixed so a spreadsheet never executes it. */
    private static function safeCell(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^\+\d+$/', $v) ? "'" . $v : $v;
    }

    private static function clean(?string $v): string
    {
        $v = trim((string) $v);
        // Placeholders a form or an import may have left are not names.
        if ($v === '' || preg_match('/^(guest|unknown|n\/?a|none|customer|walk[- ]?in|whatsapp|bethany contact.*|-+)$/i', $v)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', $v);
    }

    /** @return array{0:string,1:string} */
    private static function split(?string $name): array
    {
        $name = self::clean($name);
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
