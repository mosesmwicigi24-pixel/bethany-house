<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users');
            $table->string('action', 30);    // approved | rejected | requested_info
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->unsignedTinyInteger('step')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
    }
};