<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C4, part 2 (owner, 27 Oct 2026) — document signing and
 * courses. (Labour cost on the roster needs no table.)
 *
 * signing_requests: HR asks a person to sign one of their staff documents,
 * a handbook article or a block of text. Signing draws a signature on the
 * phone; the hub writes a one-page signing record PDF (what was signed,
 * by whom, when, from where, the document's hash, the signature) and files
 * it on the employee file as a staff document of kind `signed`
 * (signed_document_id). Declining needs a note. Cancelling is HR's.
 *
 * courses: short training paths — lessons to read in order and a quiz at
 * the end (pass_mark %). course_progress is one row per person per course:
 * the lessons done, the attempts, the best score, and completed_at when
 * passed. A required course shows on the Me page until it is done and on
 * the employee file either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 10);                                   // document | article | text
            $t->foreignId('staff_document_id')->nullable()->constrained('staff_documents')->nullOnDelete();
            $t->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $t->string('title', 140);
            $t->text('body')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->string('status', 10)->default('pending');            // pending | signed | declined | cancelled
            $t->timestamp('signed_at')->nullable();
            $t->string('signature_path', 255)->nullable();
            $t->string('signed_ip', 45)->nullable();
            $t->string('source_hash', 64)->nullable();
            $t->foreignId('signed_document_id')->nullable()->constrained('staff_documents')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('title', 140);
            $t->text('description')->nullable();
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $t->boolean('required')->default(false);
            $t->unsignedSmallInteger('pass_mark')->default(80);
            $t->timestamp('published_at')->nullable();
            $t->timestamp('announced_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('course_lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->string('title', 140);
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('course_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->text('question');
            $t->json('options');
            $t->unsignedSmallInteger('answer');                      // index into options
            $t->timestamps();
        });
        Schema::create('course_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->json('lessons_done')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('best_score')->nullable();
            $t->boolean('passed')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_progress');
        Schema::dropIfExists('course_questions');
        Schema::dropIfExists('course_lessons');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('signing_requests');
    }
};
