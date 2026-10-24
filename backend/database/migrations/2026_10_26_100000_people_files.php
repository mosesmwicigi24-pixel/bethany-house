<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 2 — the employee file (owner, 24 Oct 2026).
 *
 * employee_profiles: one row per staff member beside `users` (which stays
 * the sign-in record). Identifiers and pay details are encrypted at rest by
 * the model (Laravel `encrypted` cast) and redacted in the audit trail; the
 * API masks them unless the caller holds hr.view_sensitive and has stepped
 * up in the last few minutes.
 *
 * staff_documents: files on the private disk (ID copy, contract,
 * certificates) with an optional expiry so hr:document-expiry can warn.
 * Removal is a mark, never a delete; the file stays for the record.
 *
 * public_holidays: Kenya's fixed-date holidays for 2026 and 2027 plus the
 * Easter pair are seeded here as reference data. Eid al-Fitr, Eid al-Adha
 * and Diwali are gazetted each year on dates not known in advance, so they
 * are NOT invented: the owner adds them from Settings › Holidays when the
 * gazette is out. Rows seeded here carry is_confirmed = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('job_title', 120)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('employment_type', 16)->default('permanent'); // permanent | contract | casual | intern
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('date_of_birth')->nullable();
            // Encrypted by the model; text because ciphertext is long.
            $table->text('national_id_no')->nullable();
            $table->text('kra_pin')->nullable();
            $table->text('nssf_no')->nullable();
            $table->text('shif_no')->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('emergency_contact_relationship', 60)->nullable();
            $table->string('pay_method', 16)->nullable(); // mpesa | bank | cash
            $table->text('mpesa_phone')->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_branch', 80)->nullable();
            $table->string('bank_account_name', 120)->nullable();
            $table->text('bank_account_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('department');
        });

        Schema::create('staff_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24); // national_id | kra_pin | nssf | shif | contract | certificate | passport_photo | medical | other
            $table->string('title', 120);
            $table->string('disk', 16)->default('private');
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('original_name')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'removed_at']);
            $table->index('expires_at');
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name', 120);
            $table->char('country', 2)->default('KE');
            $table->boolean('is_confirmed')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['date', 'country']);
        });

        $rows = [];
        foreach ([2026, 2027] as $y) {
            foreach ([
                "$y-01-01" => "New Year's Day",
                "$y-05-01" => 'Labour Day',
                "$y-06-01" => 'Madaraka Day',
                "$y-10-10" => 'Mazingira Day',
                "$y-10-20" => 'Mashujaa Day',
                "$y-12-12" => 'Jamhuri Day',
                "$y-12-25" => 'Christmas Day',
                "$y-12-26" => 'Boxing Day',
            ] as $date => $name) {
                $rows[] = ['date' => $date, 'name' => $name];
            }
            $easter = (new DateTimeImmutable("$y-03-21"))->modify('+' . easter_days($y) . ' days');
            $rows[] = ['date' => $easter->modify('-2 days')->format('Y-m-d'), 'name' => 'Good Friday'];
            $rows[] = ['date' => $easter->modify('+1 day')->format('Y-m-d'), 'name' => 'Easter Monday'];
        }
        $now = now();
        DB::table('public_holidays')->insertOrIgnore(array_map(fn ($r) => $r + [
            'country' => 'KE', 'is_confirmed' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('staff_documents');
        Schema::dropIfExists('employee_profiles');
    }
};
