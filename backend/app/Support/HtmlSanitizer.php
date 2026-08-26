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

        $clean = self::purify($raw);

        return trim($clean) === '' ? null : $clean;
    }

    /**
     * Purify, and never let the ENGINE's absence be the reason a write or a
     * deploy fails.
     *
     * This method exists because it already did fail. The backfill migration
     * that came with this class died in production on "Target class [purifier]
     * does not exist", box-deploy treats a failed migration as fatal, and the
     * hub sat three releases behind for as long as it took to work out why.
     * The package was in composer.json and the container disagreed — Laravel
     * discovers providers into bootstrap/cache, and docker-compose mounts a
     * named volume over exactly that directory, so discovery is frozen on the
     * day the volume was made.
     *
     * A class whose job is "make this string safe" should not be able to stop a
     * deploy over that, so it now recovers in the two ways available to it:
     *
     *   1. The package is installed but not registered — the volume case. Ask
     *      for the provider directly; nothing about that needs the manifest.
     *   2. The package is not there at all. Strip every tag. That loses the
     *      note's formatting, which is a real cost and the reason it is the
     *      second choice, but it is STRICTER than the allow-list above rather
     *      than looser: the one thing that must never happen is unsanitised
     *      HTML reaching a manager's browser because a container was odd.
     */
    /**
     * Is the real engine usable in this container?
     *
     * Public because the difference matters to callers. Degrading to plain text
     * is right for a NEW value — the person is at the keyboard and the note is
     * safe either way. It is wrong for rewriting rows written months ago, where
     * the formatting cannot be typed back and the migration's down() restores
     * nothing. The backfill asks first and stands down; the write path does not
     * need to.
     */
    public static function engineAvailable(): bool
    {
        if (!app()->bound('purifier') && class_exists(\Mews\Purifier\PurifierServiceProvider::class)) {
            app()->register(\Mews\Purifier\PurifierServiceProvider::class);
        }

        return app()->bound('purifier');
    }

    private static function purify(string $raw): string
    {
        if (self::engineAvailable()) {
            return Purifier::clean($raw, self::SENTIMENTS_CONFIG);
        }

        report(new \RuntimeException(
            'HtmlSanitizer: mews/purifier is unavailable in this container; '
            . 'sentiments were stripped to plain text rather than left unsanitised.'
        ));

        return strip_tags($raw);
    }
}
