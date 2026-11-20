<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product reviews written on the storefront (HUB_CONTRACT §8).
 *
 * product_reviews was built for reviews by hub users (user_id NOT NULL). A
 * storefront customer has no hub account, so a guest review carries the name
 * and email they typed instead, plus the storefront's client_request_id so a
 * retried submission can never become a second row. `source` tells the two
 * apart in admin. Existing rows are untouched: they keep their user and read
 * as source 'hub'.
 *
 * Rollback drops the four new columns; user_id stays nullable (a NOT NULL
 * could not be restored while guest rows exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('author_name', 120)->nullable()->after('order_id');
            $table->string('author_email', 255)->nullable()->after('author_name');
            $table->string('client_request_id', 100)->nullable()->unique()->after('author_email');
            $table->string('source', 20)->default('hub')->after('client_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['author_name', 'author_email', 'client_request_id', 'source']);
        });
    }
};
