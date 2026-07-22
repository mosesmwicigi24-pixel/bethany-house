<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;

class SiteController extends Controller
{
    /**
     * Public: the storefront's live seasonal "skin".
     *
     * Returns the currently-active liturgical season (subtle palette + motif),
     * its Blessed Friday campaign (if a linked promotion is running — the
     * discount is server-authoritative and lives on the promotion), and an
     * optional seasonal banner. Out of season → { season: null } and the
     * storefront keeps its default navy/gold brand look.
     *
     * A dated season always wins over a windowless "default" season. Theming
     * must never break the storefront, so any failure degrades to the default.
     */
    public function theme()
    {
        try {
            $season = Season::active()
                ->whereNotNull('starts_at')          // a dated season wins…
                ->orderByDesc('priority')
                ->orderByDesc('starts_at')
                ->with(['promotion', 'banner'])
                ->first()
                ?? Season::active()                  // …else a windowless default, if one exists
                    ->whereNull('starts_at')
                    ->orderByDesc('priority')
                    ->with(['promotion', 'banner'])
                    ->first();

            if (!$season) {
                return response()->json(['season' => null, 'campaign' => null, 'banner' => null]);
            }

            $promo = $season->promotion;
            $campaign = ($promo && $promo->isRunning()) ? [
                'name'           => $promo->name,
                'discount_type'  => $promo->discount_type,   // percentage | fixed
                'discount_value' => (float) $promo->discount_value,
                'ends_at'        => optional($promo->ends_at)->toIso8601String(),
            ] : null;

            $b = $season->banner;
            $banner = ($b && $b->is_active) ? [
                'title'     => $b->title,
                'subtitle'  => $b->subtitle,
                'image_url' => $b->image_url,
                'link_url'  => $b->link_url,
                'link_text' => $b->link_text,
            ] : null;

            return response()->json([
                'season' => [
                    'key'       => $season->key,
                    'name'      => $season->name,
                    'tagline'   => $season->tagline,
                    'scripture' => $season->scripture,
                    'theme'     => $season->theme,
                    'ends_at'   => optional($season->ends_at)->toIso8601String(),
                ],
                'campaign' => $campaign,
                'banner'   => $banner,
            ]);
        } catch (\Throwable $e) {
            // Never break the storefront over theming — degrade to the default look.
            return response()->json(['season' => null, 'campaign' => null, 'banner' => null]);
        }
    }
}
