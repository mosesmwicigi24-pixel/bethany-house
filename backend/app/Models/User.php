<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Enums\UserType;

class User extends Authenticatable
{
    use HasApiTokens,
        HasFactory,
        Notifiable,
        SoftDeletes,
        HasRoles,
        LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'email',
        'phone',
        'password',
        'first_name',
        'last_name',
        'user_type',
        'status',
        'email_verified_at',
        'phone_verified_at',
        'must_setup_2fa',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_enabled_at',
        'two_factor_secret_temp',
        'two_factor_setup_started_at',
        'preferred_language',
        'preferred_currency',
        'avatar_url',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_type'                  => UserType::class,
        'email_verified_at'          => 'datetime',
        'phone_verified_at'          => 'datetime',
        'must_setup_2fa'             => 'boolean',
        'two_factor_enabled'         => 'boolean',
        'two_factor_enabled_at'      => 'datetime',
        'last_login_at'              => 'datetime',
        'deleted_at'                 => 'datetime',
        'two_factor_setup_started_at'=> 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($user->user_type)) {
                $user->user_type = UserType::CUSTOMER;
            }
        });
    }

    // =========================================================================
    // ACTIVITY LOG
    // =========================================================================

    /**
     * Tell Spatie to always use the 'sanctum' guard for permission/role checks.
     *
     * Without this, Spatie's getDefaultGuardName() inspects config/auth.php and
     * finds 'web' as the default guard (since the User model is listed under the
     * web provider). This causes $user->can('some.permission') to look up
     * permissions for guard 'web' and throw "no permission for guard web".
     *
     * Setting $guard_name here makes every hasRole(), hasPermissionTo(), and
     * Gate can() call resolve against the sanctum guard automatically.
     */
    protected string $guard_name = 'sanctum';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'email', 'phone', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get the user's full name.
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get two factor recovery codes as array.
     */
    public function getRecoveryCodesAttribute($value): array
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * Set two factor recovery codes.
     */
    public function setRecoveryCodesAttribute($value): void
    {
        $this->attributes['two_factor_recovery_codes'] = is_array($value)
            ? json_encode($value)
            : $value;
    }

    // =========================================================================
    // AUTH OVERRIDES
    // =========================================================================

    public function getAuthPassword()
    {
        return $this->password;
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    // =========================================================================
    // TYPE CHECKS
    // =========================================================================

    public function isSystem(): bool
    {
        return $this->user_type === UserType::SYSTEM;
    }

    public function isStaff(): bool
    {
        return $this->user_type === UserType::STAFF;
    }

    public function isCustomer(): bool
    {
        return $this->user_type === UserType::CUSTOMER;
    }

    /**
     * Only system and staff users may access the admin panel.
     * Used by adminLogin() and adminVerify2fa() as a gate.
     */
    public function canAccessAdmin(): bool
    {
        return $this->isSystem() || $this->isStaff();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Mint an API access token with a per-token expiry (audit SEC-5).
     *
     * Unlike the global `sanctum.expiration` (which retroactively expires ALL
     * tokens by created_at, i.e. would log everyone out on rollout), this stamps
     * only the newly-issued token's `expires_at`, leaving existing tokens intact.
     * TTL of 0/null disables the per-token expiry.
     */
    public function createAuthToken(string $name): \Laravel\Sanctum\NewAccessToken
    {
        $ttl = (int) config('sanctum.access_ttl_minutes', 0);
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        return $this->createToken($name, ['*'], $expiresAt);
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super_admin', 'admin']);
    }

    public function getUserTypeEnum(): UserType
    {
        return $this->user_type;
    }

    public function getUserTypeLabel(): string
    {
        return $this->user_type->label();
    }

    // =========================================================================
    // 2FA HELPERS
    // =========================================================================

    public function needsTwoFactorSetup(): bool
    {
        return $this->must_setup_2fa && !$this->two_factor_enabled;
    }

    public function hasActiveSetup(): bool
    {
        if (empty($this->two_factor_secret_temp)) {
            return false;
        }

        if (
            $this->two_factor_setup_started_at &&
            $this->two_factor_setup_started_at->diffInHours(now()) > 24
        ) {
            return false;
        }

        return true;
    }

    public function cancelTwoFactorSetup(): void
    {
        $this->update([
            'two_factor_secret_temp'      => null,
            'two_factor_setup_started_at' => null,
        ]);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * All outlets this user is assigned to (via outlet_user pivot).
     */
    public function outlets()
    {
        return $this->belongsToMany(Outlet::class, 'outlet_user')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    /**
     * The user's primary outlet.
     * Returns the outlet flagged as primary, falling back to the first assigned
     * outlet, or null if the user has no outlet assignment yet.
     *
     * Used by the React admin to pre-select the active outlet context
     * (POS session, stock views, etc.)
     */
    public function primaryOutlet(): ?\App\Models\Outlet
    {
        return $this->outlets()
                    ->wherePivot('is_primary', true)
                    ->first()
            ?? $this->outlets()->first();
    }

    /**
     * User's saved addresses.
     */
    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Orders placed by this user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Product reviews written by this user.
     */
    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Audit log entries for this user.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * The user's active shopping cart.
     */
    public function cart()
    {
        return $this->hasOne(ShoppingCart::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSystemUsers($query)
    {
        return $query->where('user_type', UserType::SYSTEM->value);
    }

    public function scopeStaffUsers($query)
    {
        return $query->where('user_type', UserType::STAFF->value);
    }

    public function scopeCustomers($query)
    {
        return $query->where('user_type', UserType::CUSTOMER->value);
    }

    /**
     * Users who can access the admin panel (system + staff).
     */
    public function scopeAdminUsers($query)
    {
        return $query->whereIn('user_type', [
            UserType::SYSTEM->value,
            UserType::STAFF->value,
        ]);
    }
}