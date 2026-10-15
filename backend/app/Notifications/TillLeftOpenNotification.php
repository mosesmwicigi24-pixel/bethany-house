<?php

namespace App\Notifications;

/**
 * The morning check's two reminders (owner, 2026-10-15):
 *  - evening: to the cashier whose till is still open — close it tonight;
 *  - morning: to the outlet's manager and the owner — these tills were left
 *    open overnight, and their cashiers cannot sell until they close them.
 */
class TillLeftOpenNotification extends BaseNotification
{
    /** @param list<string> $names cashiers (morning), empty for the evening nudge */
    public function __construct(
        private string $when,          // 'evening' | 'morning'
        private string $outletName,
        private int $count,
        private array $names = [],
        private ?string $tradingDay = null,
    ) {}

    public function toArray($notifiable): array
    {
        if ($this->when === 'evening') {
            return $this->payload(
                title:     'Your till is still open',
                body:      "Write today's end-of-day report and count your drawer at {$this->outletName} before you leave. "
                         . 'An open till blocks selling tomorrow until it is closed.',
                actionUrl: '/pos',
                icon:      'payment',
                extra:     ['outlet' => $this->outletName, 'trading_day' => $this->tradingDay],
            );
        }

        $who = $this->names === [] ? '' : ': ' . implode(', ', $this->names);

        return $this->payload(
            title:     $this->count === 1 ? '1 till left open overnight' : "{$this->count} tills left open overnight",
            body:      "At {$this->outletName}{$who}. They must write their report and count the drawer before selling today.",
            actionUrl: '/pos/tills',
            icon:      'payment',
            extra:     ['outlet' => $this->outletName, 'count' => $this->count],
        );
    }
}
