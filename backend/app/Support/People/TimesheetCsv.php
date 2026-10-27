<?php

namespace App\Support\People;

use App\Models\PayPeriod;
use App\Models\Timesheet;

/**
 * The approved-hours CSV of a pay period: one row per timesheet snapshot,
 * hours to two decimals. The one builder behind GET /timesheets/export and
 * the C6 "locked period CSV" email, so the two files are the same file.
 */
class TimesheetCsv
{
    public const COLUMNS = ['user_id', 'name', 'email', 'period_start', 'period_end', 'status', 'regular_hours', 'overtime_hours', 'rest_day_hours', 'holiday_hours', 'paid_leave_days', 'unpaid_leave_days', 'days_worked', 'approved_at'];

    public static function filename(PayPeriod $period): string
    {
        return 'timesheets-' . $period->starts_on->toDateString() . '.csv';
    }

    /** The file's bytes (UTF-8 with a BOM so spreadsheets read the names right). */
    public static function build(PayPeriod $period): string
    {
        $sheets = Timesheet::with('user:id,first_name,last_name,email')->where('pay_period_id', $period->id)->orderBy('user_id')->get();
        $h = fn (?int $m) => $m === null ? '' : number_format($m / 60, 2, '.', '');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS);
        foreach ($sheets as $s) {
            fputcsv($out, [
                $s->user?->id, trim(($s->user?->first_name ?? '') . ' ' . ($s->user?->last_name ?? '')), $s->user?->email,
                $period->starts_on->toDateString(), $period->ends_on->toDateString(), $s->status,
                $h($s->regular_minutes), $h($s->overtime_minutes), $h($s->rest_day_minutes), $h($s->holiday_minutes),
                $s->paid_leave_days, $s->unpaid_leave_days, $s->days_worked, $s->approved_at?->toDateTimeString(),
            ]);
        }
        rewind($out); $csv = stream_get_contents($out); fclose($out);
        return $csv;
    }
}
