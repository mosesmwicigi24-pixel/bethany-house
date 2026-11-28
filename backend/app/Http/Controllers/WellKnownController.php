<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The app-link association files (native apps programme, Step 2).
 * Configuration and the reason each file may answer 404: config/applinks.php.
 */
class WellKnownController extends Controller
{
    /** iOS / iPadOS / macOS universal links. Apple requires JSON with no extension. */
    public function appleAppSiteAssociation(): JsonResponse
    {
        $team   = (string) config('applinks.apple.team_id');
        $bundle = (string) config('applinks.apple.bundle_id');
        abort_if($team === '' || $bundle === '', 404);

        $appId   = "{$team}.{$bundle}";
        $include = (array) config('applinks.paths.include', []);
        $exclude = (array) config('applinks.paths.exclude', []);

        $components = [];
        foreach ($exclude as $p) {
            $components[] = ['/' => $p, 'exclude' => true];
        }
        foreach ($include as $p) {
            $components[] = ['/' => $p];
        }

        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs'     => [$appId],
                    'components' => $components,
                ]],
            ],
            // Passkeys registered on the Hub may be used by the app and vice versa.
            'webcredentials' => ['apps' => [$appId]],
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /** Android App Links (Digital Asset Links). */
    public function assetLinks(): JsonResponse
    {
        $targets = [
            [(string) config('applinks.android.package'), (array) config('applinks.android.sha256_fingerprints', [])],
            [(string) config('applinks.android.tailors_package'), (array) config('applinks.android.tailors_sha256_fingerprints', [])],
        ];
        $targets = array_values(array_filter($targets, fn (array $target) => $target[0] !== '' && $target[1] !== []));
        abort_if($targets === [], 404);
        return response()->json(array_map(fn (array $target) => [
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => [
                'namespace'                => 'android_app',
                'package_name'             => $target[0],
                'sha256_cert_fingerprints' => array_values($target[1]),
            ],
        ], $targets))->header('Cache-Control', 'public, max-age=3600');
    }
}
