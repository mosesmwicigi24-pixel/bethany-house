<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * php artisan permission:audit
 *
 * Read-only. Reconciles what the permission table CONTAINS against what the
 * code DECLARES and what the code ENFORCES, and prints the three ways they can
 * disagree.
 *
 * This exists because the obvious question — "does the database match the
 * code?" — could not be answered without it, and guessing at it from the Roles
 * screen's permission counts produced a wrong answer twice. The counts are
 * inflated by a legacy space-separated vocabulary ('view orders') that a
 * removed seeder created alongside the dotted one the application actually
 * checks ('orders.view'). Both sit on the same guard in the same table.
 *
 * Run this before `permission:sync --prune` ever exists. Undeclared rows are
 * not automatically safe to delete: some legacy names are still enforced by
 * `can:` middleware in routes/web.php.
 */
class AuditPermissions extends Command
{
    protected $signature   = 'permission:audit {--json : Emit machine-readable output}';
    protected $description = 'Reconcile the permission table against what the code declares and enforces.';

    public function handle(): int
    {
        $declared = array_keys(SyncPermissions::PERMISSIONS);
        $inDb     = Permission::where('guard_name', 'sanctum')->pluck('name')->all();
        $enforced = $this->enforcedPermissions();

        $undeclared = array_values(array_diff($inDb, $declared));
        $missing    = array_values(array_diff($declared, $inDb));
        $phantom    = array_values(array_diff($enforced, $declared));
        $inert      = array_values(array_diff($declared, $enforced));

        // A legacy name is any undeclared row that is not dotted — the removed
        // seeder wrote 'view orders', the application checks 'orders.view'.
        $legacy = array_values(array_filter($undeclared, fn ($p) => !str_contains($p, '.')));
        $other  = array_values(array_diff($undeclared, $legacy));

        if ($this->option('json')) {
            $this->line(json_encode(compact('undeclared', 'missing', 'phantom', 'inert', 'legacy', 'other'), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Permission audit');
        $this->line(sprintf('  declared in code : %d', count($declared)));
        $this->line(sprintf('  present in DB    : %d', count($inDb)));
        $this->line(sprintf('  enforced in code : %d', count($enforced)));
        $this->newLine();

        $this->report(
            'PHANTOM — enforced but never declared',
            $phantom,
            'permission:sync never creates these, so nobody can hold them. The gate they guard answers only to super_admin, by accident of its bypass.',
        );

        $this->report(
            'LEGACY — in the DB under the old space-separated vocabulary',
            $legacy,
            'Written by the removed PermissionsSeeder. NOT automatically safe to delete: some are still enforced by `can:` middleware in routes/web.php.',
        );

        $this->report(
            'UNDECLARED (dotted) — in the DB, not in the catalogue',
            $other,
            'Hand-created, or left by an older version of the catalogue. Check each before removing.',
        );

        $this->report(
            'MISSING — declared but absent from the DB',
            $missing,
            'Run permission:sync.',
        );

        $this->report(
            'INERT — declared and granted, but enforced nowhere',
            $inert,
            'These appear on the Roles screen and govern nothing. Either wire them up or remove them.',
        );

        $this->newLine();
        $this->line('Role grant counts:');
        $rows = DB::table('roles')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->groupBy('roles.name')
            ->select('roles.name', DB::raw('COUNT(role_has_permissions.permission_id) as n'))
            ->orderByDesc('n')
            ->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-24s %d', $r->name, $r->n));
        }

        return self::SUCCESS;
    }

    /** @param list<string> $items */
    private function report(string $title, array $items, string $note): void
    {
        if ($items === []) {
            $this->line("  <fg=green>✓</> {$title}: none");

            return;
        }

        sort($items);
        $this->newLine();
        $this->line("  <fg=yellow>{$title}</> (" . count($items) . ')');
        $this->line("    {$note}");
        foreach ($items as $i) {
            $this->line("      - {$i}");
        }
    }

    /**
     * Every permission string the code actually checks, from all four places a
     * gate can live: route middleware, controller can() calls, the React
     * sidebar, and the `can:` middleware inside routes/web.php's role gates.
     *
     * @return list<string>
     */
    private function enforcedPermissions(): array
    {
        $found = [];

        $scan = function (string $path, string $pattern) use (&$found): void {
            if (!is_dir($path) && !is_file($path)) {
                return;
            }
            $files = is_file($path)
                ? [$path]
                : iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)));
            foreach ($files as $file) {
                $file = (string) $file;
                if (is_dir($file) || !preg_match('/\.(php|ts|tsx)$/', $file)) {
                    continue;
                }
                // Comments are stripped first. Prose describing a permission is
                // not a gate: an unanchored scan once reported "middleware" and
                // "not role" as phantoms from the sentence "guarded by
                // permission: middleware, not role:", and a widened one picked
                // up the ->can('a.b') in this very docblock.
                $source = (string) file_get_contents($file);
                $source = preg_replace('!/\*.*?\*/!s', '', $source) ?? $source;
                $source = preg_replace('!(^|\s)//[^\n]*!', '', $source) ?? $source;

                if (preg_match_all($pattern, $source, $m)) {
                    foreach ($m[1] as $hit) {
                        foreach (preg_split('/[|,]/', $hit) as $one) {
                            $one = trim($one);
                            if ($one !== '' && $one !== 'sanctum') {
                                $found[] = $one;
                            }
                        }
                    }
                }
            }
        };

        $base = base_path();
        // 'permission:a.b|c.d,sanctum' — route middleware, both route files.
        // Anchored to the opening quote: an unanchored `permission:` also
        // matches prose, and a comment reading "guarded by permission:
        // middleware, not role:" was duly reported as two phantom permissions.
        $scan($base . '/routes', "/['\"]permission:([a-z_. |,]+)/");
        // ->can('a.b')                 — checked anywhere in the application,
        // not only in controllers: PosDiscountPolicy lives in app/Services and
        // scanning app/Http alone reported pos.discount as inert while it was
        // being enforced on every sale.
        $scan($base . '/app', "/->can\(\s*'([a-z_. ]+)'/");
        // ,can:a b                     — legacy names inside web.php role gates
        $scan($base . '/routes', "/,can:([a-z_ ]+)/");
        // "a.b" in the React sidebar's permission / anyOfPermissions keys
        $scan(dirname($base) . '/react-admin/src/components/layout/Sidebar.tsx', '/"([a-z_]+\.[a-z_]+)"/');

        return array_values(array_unique($found));
    }
}
