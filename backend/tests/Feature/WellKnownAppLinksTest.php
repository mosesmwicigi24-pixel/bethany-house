<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Native apps Step 2: the app-link association files, served only once configured. */
class WellKnownAppLinksTest extends TestCase
{
    private const AASA  = '/.well-known/apple-app-site-association';
    private const LINKS = '/.well-known/assetlinks.json';

    public function test_apple_file_is_absent_until_the_team_id_is_set(): void
    {
        config(['applinks.apple.team_id' => null]);

        $this->get(self::AASA)->assertStatus(404);
    }

    public function test_apple_file_lists_the_app_and_the_console_paths(): void
    {
        config(['applinks.apple.team_id' => 'ABCDE12345', 'applinks.apple.bundle_id' => 'ke.co.bethanyhouse.hub']);

        $res = $this->get(self::AASA)->assertOk()->assertHeader('Content-Type', 'application/json');

        $res->assertJsonPath('applinks.details.0.appIDs.0', 'ABCDE12345.ke.co.bethanyhouse.hub');
        $res->assertJsonPath('webcredentials.apps.0', 'ABCDE12345.ke.co.bethanyhouse.hub');
        $components = $res->json('applinks.details.0.components');
        $this->assertContains(['/' => '/admin/*'], $components);
        $this->assertContains(['/' => '/admin/pay/*', 'exclude' => true], $components, 'customer pages stay in the browser');
    }

    public function test_android_file_is_absent_until_a_fingerprint_is_set(): void
    {
        config(['applinks.android.sha256_fingerprints' => []]);

        $this->get(self::LINKS)->assertStatus(404);
    }

    public function test_android_file_lists_the_package_and_fingerprints(): void
    {
        config(['applinks.android.package' => 'ke.co.bethanyhouse.hub', 'applinks.android.sha256_fingerprints' => ['AA:BB', 'CC:DD']]);

        $this->get(self::LINKS)->assertOk()
            ->assertJsonPath('0.target.package_name', 'ke.co.bethanyhouse.hub')
            ->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB', 'CC:DD'])
            ->assertJsonPath('0.relation.0', 'delegate_permission/common.handle_all_urls');
    }
}
