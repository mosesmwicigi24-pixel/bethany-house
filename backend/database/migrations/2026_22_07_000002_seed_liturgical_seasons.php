<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the four Christian seasons with their liturgical palettes and 2026
 * windows. Idempotent (updateOrInsert by key) so it is safe to re-run and the
 * CMS can edit dates/colours afterwards without being overwritten on redeploy
 * — this only creates a season if its key is missing, and refreshes the seed
 * values otherwise. Theme = accent + motif only; the storefront keeps its navy
 * base. Discounts are NOT seeded — a season is theme-only until a promotion is
 * attached in the CMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $seasons = [
            [
                'key'       => 'lent-easter',
                'name'      => 'Lent → Easter',
                'tagline'   => 'He is risen — set apart for the sacred.',
                'scripture' => '“He is not here; he has risen!” — Luke 24:6',
                'theme'     => [
                    'accent' => '#6f9e79', 'accentInk' => '#2f6b46', 'accentSoft' => '#eef4ec',
                    'onAccent' => '#ffffff', 'liturgical' => 'White · Spring Green', 'motif' => 'lily',
                ],
                'starts_at' => '2026-03-01 00:00:00', 'ends_at' => '2026-04-19 23:59:59',
                'priority'  => 10, 'sort_order' => 1,
            ],
            [
                'key'       => 'pentecost',
                'name'      => 'Pentecost',
                'tagline'   => 'Tongues of fire — the Spirit poured out.',
                'scripture' => '“They saw what seemed to be tongues of fire.” — Acts 2:3',
                'theme'     => [
                    'accent' => '#c1352b', 'accentInk' => '#a52a22', 'accentSoft' => '#f7e0dd',
                    'onAccent' => '#ffffff', 'liturgical' => 'Flame Red', 'motif' => 'flame',
                ],
                'starts_at' => '2026-05-18 00:00:00', 'ends_at' => '2026-06-07 23:59:59',
                'priority'  => 10, 'sort_order' => 2,
            ],
            [
                'key'       => 'harvest',
                'name'      => 'Harvest Thanksgiving',
                'tagline'   => 'First-fruits — a season of thanksgiving.',
                'scripture' => '“Honour the Lord with the firstfruits of all your crops.” — Proverbs 3:9',
                'theme'     => [
                    'accent' => '#b5791f', 'accentInk' => '#8a5c14', 'accentSoft' => '#f4ead2',
                    'onAccent' => '#ffffff', 'liturgical' => 'Amber · Field Green', 'motif' => 'wheat',
                ],
                'starts_at' => '2026-08-01 00:00:00', 'ends_at' => '2026-08-31 23:59:59',
                'priority'  => 10, 'sort_order' => 3,
            ],
            [
                'key'       => 'advent-christmas',
                'name'      => 'Advent → Christmas',
                'tagline'   => 'Unto us a Child is born.',
                'scripture' => '“For unto us a child is born, unto us a son is given.” — Isaiah 9:6',
                'theme'     => [
                    'accent' => '#5b3a8e', 'accentInk' => '#4a2f74', 'accentSoft' => '#ece4f5',
                    'onAccent' => '#ffffff', 'liturgical' => 'Violet → Gold', 'motif' => 'star',
                ],
                'starts_at' => '2026-11-27 00:00:00', 'ends_at' => '2026-12-31 23:59:59',
                'priority'  => 10, 'sort_order' => 4,
            ],
        ];

        foreach ($seasons as $s) {
            DB::table('seasons')->updateOrInsert(
                ['key' => $s['key']],
                [
                    'name'       => $s['name'],
                    'tagline'    => $s['tagline'],
                    'scripture'  => $s['scripture'],
                    'theme'      => json_encode($s['theme']),
                    'starts_at'  => $s['starts_at'],
                    'ends_at'    => $s['ends_at'],
                    'is_active'  => true,
                    'priority'   => $s['priority'],
                    'sort_order' => $s['sort_order'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('seasons')->whereIn('key', [
            'lent-easter', 'pentecost', 'harvest', 'advent-christmas',
        ])->delete();
    }
};
