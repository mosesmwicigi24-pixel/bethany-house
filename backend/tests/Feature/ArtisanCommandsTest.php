<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Guards the fix for the broken permission-sync deploy hook. The command is
 * `permission:sync` (singular); the entrypoint used to invoke the nonexistent
 * `permissions:sync`, which silently no-op'd and logged an error on every boot.
 */
class ArtisanCommandsTest extends TestCase
{
    public function test_permission_sync_command_is_registered(): void
    {
        $this->assertArrayHasKey('permission:sync', Artisan::all());
    }

    public function test_mistyped_permissions_sync_command_does_not_exist(): void
    {
        $this->assertArrayNotHasKey('permissions:sync', Artisan::all());
    }
}
