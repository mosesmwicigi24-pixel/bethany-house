<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;
use App\Enums\UserType;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'guard_name',
        'user_type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'user_type' => UserType::class,
        'is_active' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * Scope: System roles only.
     */
    public function scopeSystem($query)
    {
        return $query->where('user_type', UserType::SYSTEM->value);
    }

    /**
     * Scope: Staff roles only.
     */
    public function scopeStaff($query)
    {
        return $query->where('user_type', UserType::STAFF->value);
    }

    /**
     * Scope: Customer roles only.
     */
    public function scopeCustomer($query)
    {
        return $query->where('user_type', UserType::CUSTOMER->value);
    }

    /**
     * Scope: Active roles only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if this is a system role.
     */
    public function isSystemRole(): bool
    {
        return $this->user_type === UserType::SYSTEM->value;
    }

    /**
     * Check if this is a staff role.
     */
    public function isStaffRole(): bool
    {
        return $this->user_type === UserType::STAFF->value;
    }

    /**
     * Check if this is a customer role.
     */
    public function isCustomerRole(): bool
    {
        return $this->user_type === UserType::CUSTOMER->value;
    }

    /**
     * Get user type enum instance.
     */
    public function getUserTypeEnum(): UserType
    {
        return UserType::from($this->user_type);
    }
}
