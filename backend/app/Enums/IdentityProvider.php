<?php

namespace App\Enums;

/**
 * The channels a customer identity can live on.
 *
 * The Meta group (WhatsApp, Instagram, Messenger, Facebook) is the focus of the
 * Neema multichannel-identity epic — those channels arrive through Meta's Graph
 * API / webhooks and carry platform-authenticated ids. SMS and WEB are included
 * so the same resolution path can absorb non-Meta contacts later.
 */
enum IdentityProvider: string
{
    case WHATSAPP  = 'whatsapp';
    case INSTAGRAM = 'instagram';
    case MESSENGER = 'messenger';
    case FACEBOOK  = 'facebook';
    case SMS       = 'sms';
    case WEB       = 'web';

    /**
     * Channels served by Meta's Graph API — their ids are platform-verified.
     *
     * @return array<int, self>
     */
    public static function metaChannels(): array
    {
        return [self::WHATSAPP, self::INSTAGRAM, self::MESSENGER, self::FACEBOOK];
    }

    /**
     * Is this a Meta-operated channel?
     */
    public function isMeta(): bool
    {
        return in_array($this, self::metaChannels(), true);
    }

    /**
     * Human-facing label.
     */
    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP  => 'WhatsApp',
            self::INSTAGRAM => 'Instagram',
            self::MESSENGER => 'Messenger',
            self::FACEBOOK  => 'Facebook',
            self::SMS       => 'SMS',
            self::WEB       => 'Web',
        };
    }

    /**
     * All provider values (e.g. for validation `in:` rules).
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
