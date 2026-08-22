<?php

namespace App\Support;

use Mews\Purifier\Facades\Purifier;

/**
 * One allow-list for staff-authored rich text. Today the only such field is the
 * cash-register EoD "sentiments" note, which is entered through a WYSIWYG editor
 * and was previously stored and rendered as raw HTML — a stored-XSS vector that
 * ran in a manager's admin session (see EodReportsPage / the EoD email).
 *
 * Sanitising HERE, once, keeps the rule in a single place used by both the write
 * path (PosController::saveUserEodReport) and the one-time backfill of existing
 * rows. Cache.DefinitionImpl is disabled so no writable HTMLPurifier cache
 * directory is required in CI or a fresh container.
 */
class HtmlSanitizer
{
    /** Basic formatting a daily note legitimately uses — nothing that can execute. */
    private const SENTIMENTS_CONFIG = [
        'HTML.Allowed'           => 'p,br,b,strong,i,em,u,ul,ol,li,h3,h4',
        'AutoFormat.RemoveEmpty' => true,
        'Cache.DefinitionImpl'   => null,
    ];

    /**
     * Return safe HTML for a sentiments value, or null when the input is absent
     * or reduces to nothing once emptied tags are stripped — matching the
     * column's "no note" state rather than storing an empty string.
     */
    public static function sentiments(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $clean = Purifier::clean($raw, self::SENTIMENTS_CONFIG);

        return trim($clean) === '' ? null : $clean;
    }
}
