<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('subject', 255);
            $table->text('preview_text')->nullable();
            $table->longText('html_body');
            $table->text('plain_body')->nullable();
            $table->string('from_name', 100)->nullable();
            $table->string('from_email', 150)->nullable();
            $table->string('reply_to', 150)->nullable();
            $table->string('status', 20)->default('draft');
            // draft | scheduled | sending | sent | cancelled
            $table->string('audience', 30)->default('all_customers');
            // all_customers | active | business | individual | custom
            $table->json('audience_filters')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->integer('recipient_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->integer('clicked_count')->default(0);
            $table->integer('bounced_count')->default(0);
            $table->integer('unsubscribed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};