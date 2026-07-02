<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEventColumnToActivityLogTable extends Migration
{
    public function up()
    {
        $connection = config('activitylog.database_connection');
        $tableName  = config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasColumn($tableName, 'event')) {
            return;
        }

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->string('event')->nullable()->after('subject_type');
        });
    }

    public function down()
    {
        $connection = config('activitylog.database_connection');
        $tableName  = config('activitylog.table_name', 'activity_log');

        if (!Schema::connection($connection)->hasColumn($tableName, 'event')) {
            return;
        }

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
}