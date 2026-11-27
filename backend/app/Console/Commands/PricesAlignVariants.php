<?php

namespace App\Console\Commands;

use App\Models\ProductPrice;
use App\Models\User;
use App\Support\VariantPriceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * prices:align-variants — bring variants that are not at their product's
 * price to it (owner, 2026-11-26; App\Support\VariantPriceSync).
 *
 *   prices:align-variants                    counts only (read-only)
 *   prices:align-variants --mail             email the full list to the owner
 *                                            (audit.owner_email); counts printed
 *   prices:align-variants --apply --actor=ID set those variants to their
 *                                            product's price, on the audit trail
 *
 * A dry run unless --apply. Prints counts only — the repository's workflow
 * logs are public, and a price list is not for them. Selling prices and the
 * sale window only; a variant's cost is never changed. Running it again
 * after --apply changes nothing.
 */
class PricesAlignVariants extends Command
{
    protected $signature = 'prices:align-variants {--mail} {--apply} {--actor=}';

    protected $description = 'Variants not at their product\'s price: count, email the list, or align them (dry run unless --apply --actor).';

    public function handle(): int
    {
        $rows     = VariantPriceSync::outOfStep();
        $eff      = fn ($regular, $sale) => $sale !== null && (float) $sale > 0 ? (float) $sale : (float) $regular;
        $missing  = $rows->filter(fn ($r) => $r->variant_regular === null);
        $priced   = $rows->reject(fn ($r) => $r->variant_regular === null);
        $up       = $priced->filter(fn ($r) => $eff($r->product_regular, $r->product_sale) > $eff($r->variant_regular, $r->variant_sale));
        $down     = $priced->filter(fn ($r) => $eff($r->product_regular, $r->product_sale) < $eff($r->variant_regular, $r->variant_sale));

        $this->line('Products with a variant out of step: ' . $rows->pluck('product_id')->unique()->count());
        $this->line('Variant prices out of step: ' . $rows->count());
        $this->line('Would go up: ' . $up->count());
        $this->line('Would go down: ' . $down->count());
        $this->line('Same price, sale details differ: ' . ($priced->count() - $up->count() - $down->count()));
        $this->line('Variant has no price in that currency: ' . $missing->count());

        if ($this->option('apply')) {
            $actor = ctype_digit((string) $this->option('actor')) ? User::find((int) $this->option('actor')) : null;
            if (!$actor || !$actor->isActive() || !$actor->hasRole('super_admin')) {
                $this->error('--apply needs --actor=<the user id of an active super admin>. Nothing changed.');

                return self::FAILURE;
            }

            $written = 0;
            foreach ($rows->pluck('product_id')->unique() as $productId) {
                $written += DB::transaction(fn () => VariantPriceSync::followProduct((int) $productId, $actor, 'prices:align-variants'));
            }
            $this->line("Aligned now: {$written}");
            $this->line('Still out of step: ' . VariantPriceSync::outOfStep()->count());

            return self::SUCCESS;
        }

        if ($this->option('mail') && $rows->isNotEmpty()) {
            $to = trim((string) config('audit.owner_email'));
            if ($to === '') {
                $this->error('No owner email configured (AUDIT_OWNER_EMAIL).');

                return self::FAILURE;
            }

            $money = fn ($regular, $sale) => $regular === null ? 'no price'
                : number_format((float) $regular, 2) . ($sale !== null ? ' (sale ' . number_format((float) $sale, 2) . ')' : '');
            $lines = [];
            foreach ($rows->groupBy('product_sku') as $sku => $group) {
                $lines[] = $sku;
                foreach ($group as $r) {
                    $lines[] = "   {$r->variant_sku}  {$r->currency_code}  now {$money($r->variant_regular, $r->variant_sale)}"
                        . "  →  product {$money($r->product_regular, $r->product_sale)}";
                }
            }
            $body = "Variants that are not at their product's price. Nothing has been changed.\n"
                . "A sale of a variant charges the variant's price (\"now\"); aligning sets it to the product's.\n"
                . "Cost prices are not touched.\n\n" . implode("\n", $lines) . "\n";
            Mail::raw($body, fn ($m) => $m->to($to)->subject('Bethany Hub — ' . $rows->count() . ' variant prices not at their product\'s price'));
            $this->line('List emailed to the owner.');

            return self::SUCCESS;
        }

        $this->line('Dry run: nothing changed.');

        return self::SUCCESS;
    }
}
