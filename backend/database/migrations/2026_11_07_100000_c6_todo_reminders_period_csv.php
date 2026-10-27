<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People C6 — two loose ends from the Connecteam review.
 *
 *  todo_due    the person is reminded N minutes before a to-do is due (once),
 *              and whoever gave it hears once when it goes overdue. Off until
 *              switched on; `todos.overdue_told_at` records the second nudge.
 *  period_csv  when a pay period is locked, the approved-hours CSV (the same
 *              file as GET /timesheets/export) is emailed to finance. Off by
 *              default: the owner switches it on once the box can send mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $t) {
            $t->timestamp('overdue_told_at')->nullable()->after('reminded_at');
        });
        $now = now();
        foreach ([
            ['key' => 'todo_due',   'enabled' => false, 'threshold' => 60, 'recipients' => 'managers'],
            ['key' => 'period_csv', 'enabled' => false, 'threshold' => 0,  'recipients' => 'finance'],
        ] as $row) {
            if (!DB::table('automations')->where('key', $row['key'])->exists()) {
                DB::table('automations')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('automations')->whereIn('key', ['todo_due', 'period_csv'])->delete();
        Schema::table('todos', fn (Blueprint $t) => $t->dropColumn('overdue_told_at'));
    }
};
