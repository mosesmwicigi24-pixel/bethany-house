<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The employee file beside the sign-in record (Bethany People PR 2).
 * SENSITIVE holds the fields encrypted at rest, masked by the API unless the
 * caller holds hr.view_sensitive and has stepped up, and redacted in the
 * audit trail (config/audit.php redacted_columns).
 */
class EmployeeProfile extends Model
{
    use HasFactory;

    public const EMPLOYMENT_TYPES = ['permanent', 'contract', 'casual', 'intern'];
    public const PAY_METHODS      = ['mpesa', 'bank', 'cash'];
    public const SENSITIVE        = ['national_id_no', 'kra_pin', 'nssf_no', 'shif_no', 'mpesa_phone', 'bank_account_no'];

    protected $fillable = [
        'user_id', 'job_title', 'department', 'employment_type', 'hire_date', 'end_date', 'date_of_birth',
        'national_id_no', 'kra_pin', 'nssf_no', 'shif_no',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'pay_method', 'mpesa_phone', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no',
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'hire_date'       => 'date:Y-m-d',
        'end_date'        => 'date:Y-m-d',
        'date_of_birth'   => 'date:Y-m-d',
        'national_id_no'  => 'encrypted',
        'kra_pin'         => 'encrypted',
        'nssf_no'         => 'encrypted',
        'shif_no'         => 'encrypted',
        'mpesa_phone'     => 'encrypted',
        'bank_account_no' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** "•••• 4521" — the last few characters only, enough to recognise, never enough to use. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $keep = strlen($value) > 4 ? 3 : 1;
        return str_repeat('•', max(4, strlen($value) - $keep)) . substr($value, -$keep);
    }
}
