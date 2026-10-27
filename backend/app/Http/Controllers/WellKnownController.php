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
        $package      = (string) config('applinks.android.package');
        $fingerprints = (array) config('applinks.android.sha256_fingerprints', []);
        abort_if($package === '' || $fingerprints === [], 404);

        return response()->json([[
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => [
                'namespace'                => 'android_app',
                'package_name'             => $package,
                'sha256_cert_fingerprints' => array_values($fingerprints),
            ],
        ]])->header('Cache-Control', 'public, max-age=3600');
    }
}
