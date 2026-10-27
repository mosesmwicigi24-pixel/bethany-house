<?php

namespace App\Mail;

use App\Models\PayPeriod;
use App\Support\People\TimesheetCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * People C6: the approved-hours CSV of a pay period that has just been
 * locked, sent to finance when the `period_csv` automation is on.
 * Rendered by resources/views/mail/period-locked-csv.blade.php.
 */
class PeriodLockedCsvMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PayPeriod $period,
        public readonly string $lockedBy,
        public readonly int $sheets,
        public readonly int $leftOut,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Timesheets locked: ' . $this->period->starts_on->format('j M') . ' – ' . $this->period->ends_on->format('j M Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.period-locked-csv', with: [
            'group'    => $this->period->group?->name ?? 'Pay group',
            'range'    => $this->period->starts_on->format('j M') . ' – ' . $this->period->ends_on->format('j M Y'),
            'lockedBy' => $this->lockedBy,
            'sheets'   => $this->sheets,
            'leftOut'  => $this->leftOut,
            'url'      => rtrim(config('app.url'), '/') . '/admin/people/timesheets',
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => TimesheetCsv::build($this->period), TimesheetCsv::filename($this->period))->withMime('text/csv')];
    }
}
