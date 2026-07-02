<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds default keys into the existing `settings` table for backup storage
 * configuration. Stored alongside other app settings so it's editable through
 * the same SettingController-style key/value mechanism — no new config table
 * needed. Values containing secrets (S3 key/secret) are still stored as plain
 * settings rows here for simplicity; for stricter production hardening these
 * should be moved to encrypted columns or environment variables and this
 * table should only store non-secret pointers (bucket name, region, prefix).
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'backup_storage_disk'        => 'local',     // 'local' | 's3'
            'backup_local_retain_count'  => '14',
            'backup_s3_bucket'           => '',
            'backup_s3_region'           => '',
            'backup_s3_endpoint'         => '',           // for S3-compatible providers (DigitalOcean Spaces, MinIO, etc.)
            'backup_s3_key'              => '',
            'backup_s3_secret'           => '',
            'backup_s3_prefix'           => 'backups/',
            'backup_s3_use_path_style'   => '0',
            'backup_notify_email'        => '',           // optional: notify on scheduled backup failure
        ];

        $now = now();
        foreach ($defaults as $key => $value) {
            // NOTE: do not use COALESCE(created_at, ...) inside the values
            // passed to updateOrInsert(). On Postgres, the INSERT branch of
            // updateOrInsert fails with "column created_at does not exist"
            // because COALESCE can't reference a column that isn't part of
            // the row being inserted yet — that trick is only valid inside an
            // UPDATE statement, where the row (and column) already exist.
            //
            // Instead, check for an existing row explicitly so created_at is
            // set once on first insert and never touched again on repeat runs
            // (idempotent re-runs after a partial failure shouldn't falsify
            // the audit trail by bumping created_at to "now" every time).
            $exists = DB::table('settings')->where('key', $key)->exists();

            if ($exists) {
                DB::table('settings')->where('key', $key)->update([
                    'value'      => $value,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('settings')->insert([
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Seed the single backup_schedules config row if it doesn't exist yet.
        if (DB::table('backup_schedules')->count() === 0) {
            DB::table('backup_schedules')->insert([
                'is_enabled'   => false,
                'frequency'    => 'daily',
                'run_at'       => '02:00:00',
                'retain_count' => 14,
                'disk'         => 'local',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        $keys = [
            'backup_storage_disk', 'backup_local_retain_count', 'backup_s3_bucket',
            'backup_s3_region', 'backup_s3_endpoint', 'backup_s3_key', 'backup_s3_secret',
            'backup_s3_prefix', 'backup_s3_use_path_style', 'backup_notify_email',
        ];
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};