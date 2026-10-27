<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second emergency contact on the employee file (owner, 27 Oct 2026:
 * "make editable emergency contact").
 *
 * The first contact already lives on employee_profiles
 * (emergency_contact_name / _phone / _relationship, People PR 2). A second
 * one — the person to try when the first does not answer — sits beside it in
 * the same shape. Plain columns, not encrypted: an emergency contact is
 * something the floor manager must be able to read in an emergency, and the
 * first contact is already stored this way. Phones are saved as E.164
 * (App\Support\Phone::e164) by PeopleController.
 *
 * Nullable additions only — instant on Postgres, no backfill, nothing to
 * migrate. down() drops exactly these three columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('emergency_contact_2_name', 120)->nullable()->after('emergency_contact_relationship');
            $table->string('emergency_contact_2_phone', 32)->nullable()->after('emergency_contact_2_name');
            $table->string('emergency_contact_2_relationship', 60)->nullable()->after('emergency_contact_2_phone');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['emergency_contact_2_name', 'emergency_contact_2_phone', 'emergency_contact_2_relationship']);
        });
    }
};
