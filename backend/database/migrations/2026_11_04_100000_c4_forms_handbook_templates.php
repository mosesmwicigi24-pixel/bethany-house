<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C4, part 1 (owner, 27 Oct 2026) — checklists and forms,
 * the handbook, shift templates.
 *
 * form_templates: what a shop fills in. kind = checklist (opening, closing:
 * one per outlet per day, with a due time and a weekday pattern) or report
 * (incident, damage: whenever something happens). items is a list of
 * {key, label, type (check|text|number|photo), required}. outlet_id null =
 * every outlet. form_submissions: one filled form; status submitted | late
 * (after due_at) | missed (forms:remind found nothing by due + threshold and
 * wrote the row itself, user_id null; a later submission turns it late).
 * skipped_required and flagged are what the manager looks at. Photos and
 * the signature live on the private disk, paths in photos / signature_path.
 *
 * articles: the handbook. A published must-read article carries a linked
 * must-read post (post_id) so "read and understood" is the feed's own
 * tracking. shift_templates: a named start/end/label for the roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('kind', 12);                        // checklist | report
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $t->string('schedule', 8)->default('none');    // daily | none
            $t->json('weekdays')->nullable();              // ISO days; null = the attendance working days
            $t->time('due_time')->nullable();
            $t->json('items');
            $t->boolean('requires_signature')->default(false);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('form_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $t->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('for_date');
            $t->string('status', 10);                      // submitted | late | missed
            $t->timestamp('due_at')->nullable();
            $t->json('answers')->nullable();
            $t->json('photos')->nullable();
            $t->string('signature_path', 255)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('skipped_required')->default(0);
            $t->boolean('flagged')->default(false);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('managers_told_at')->nullable();
            $t->timestamps();
            $t->index(['form_template_id', 'outlet_id', 'for_date']);
            $t->index(['outlet_id', 'for_date']);
        });
        Schema::create('articles', function (Blueprint $t) {
            $t->id();
            $t->string('title', 140);
            $t->string('slug', 160)->unique();
            $t->string('category', 40)->nullable();
            $t->text('body');
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $t->boolean('must_read')->default(false);
            $t->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shift_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->cascadeOnDelete();
            $t->string('name', 60);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('label', 60)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        if (!DB::table('automations')->where('key', 'checklist_missed')->exists()) {
            DB::table('automations')->insert(['key' => 'checklist_missed', 'enabled' => true, 'threshold' => 30, 'recipients' => 'managers', 'created_at' => now(), 'updated_at' => now()]);
        }
        // The four forms every shop starts with (outlet_id null = every outlet). Edited on People › Checklists.
        $now = now();
        $forms = [
            ['name' => 'Opening checklist', 'kind' => 'checklist', 'schedule' => 'daily', 'due_time' => '09:00', 'requires_signature' => false, 'items' => [
                ['key' => 'lights', 'label' => 'Lights, fans and music on', 'type' => 'check', 'required' => true],
                ['key' => 'float', 'label' => 'Opening float counted (KES)', 'type' => 'number', 'required' => true],
                ['key' => 'till', 'label' => 'Till on, paper loaded, M-Pesa phone charged', 'type' => 'check', 'required' => true],
                ['key' => 'floor', 'label' => 'Shop floor and fitting room tidy', 'type' => 'check', 'required' => true],
                ['key' => 'display', 'label' => 'Window display photo', 'type' => 'photo', 'required' => false],
            ]],
            ['name' => 'Closing checklist', 'kind' => 'checklist', 'schedule' => 'daily', 'due_time' => '19:30', 'requires_signature' => true, 'items' => [
                ['key' => 'till_closed', 'label' => 'Till counted and closed on the Hub', 'type' => 'check', 'required' => true],
                ['key' => 'cash', 'label' => 'Cash counted (KES)', 'type' => 'number', 'required' => true],
                ['key' => 'safe', 'label' => 'Cash in the safe / banked', 'type' => 'check', 'required' => true],
                ['key' => 'power', 'label' => 'Irons, lights and AC off', 'type' => 'check', 'required' => true],
                ['key' => 'doors', 'label' => 'Doors and grille locked', 'type' => 'check', 'required' => true],
                ['key' => 'floor_photo', 'label' => 'Shop floor photo', 'type' => 'photo', 'required' => false],
            ]],
            ['name' => 'Incident report', 'kind' => 'report', 'schedule' => 'none', 'due_time' => null, 'requires_signature' => true, 'items' => [
                ['key' => 'what', 'label' => 'What happened', 'type' => 'text', 'required' => true],
                ['key' => 'when', 'label' => 'When and where', 'type' => 'text', 'required' => true],
                ['key' => 'who', 'label' => 'Who was involved (staff, customers)', 'type' => 'text', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo', 'required' => false],
            ]],
            ['name' => 'Damage report', 'kind' => 'report', 'schedule' => 'none', 'due_time' => null, 'requires_signature' => false, 'items' => [
                ['key' => 'item', 'label' => 'What was damaged', 'type' => 'text', 'required' => true],
                ['key' => 'how', 'label' => 'How it happened', 'type' => 'text', 'required' => true],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo', 'required' => true],
                ['key' => 'cost', 'label' => 'Estimated cost (KES)', 'type' => 'number', 'required' => false],
            ]],
        ];
        foreach ($forms as $f) {
            DB::table('form_templates')->insert(['name' => $f['name'], 'kind' => $f['kind'], 'outlet_id' => null, 'schedule' => $f['schedule'], 'weekdays' => null, 'due_time' => $f['due_time'],
                'items' => json_encode($f['items']), 'requires_signature' => $f['requires_signature'], 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('automations')->where('key', 'checklist_missed')->delete();
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_templates');
    }
};
