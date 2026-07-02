<?php

namespace App\Enums;

enum UserType: string
{
    case SYSTEM = 'system';
    case STAFF = 'staff';
    case CUSTOMER = 'customer';

    /**
     * Get display name for the user type.
     */
    public function label(): string
    {
        return match($this) {
            self::SYSTEM => 'System User',
            self::STAFF => 'Staff Member',
            self::CUSTOMER => 'Customer',
        };
    }

    /**
     * Get description for the user type.
     */
    public function description(): string
    {
        return match($this) {
            self::SYSTEM => 'System administrators with highest level access',
            self::STAFF => 'Company employees and staff members',
            self::CUSTOMER => 'Regular customers who shop on the platform',
        };
    }

    /**
     * Get all user types as array.
     */
    public static function toArray(): array
    {
        return [
            self::SYSTEM->value => self::SYSTEM->label(),
            self::STAFF->value => self::STAFF->label(),
            self::CUSTOMER->value => self::CUSTOMER->label(),
        ];
    }

    /**
     * Check if this is a system user type.
     */
    public function isSystem(): bool
    {
        return $this === self::SYSTEM;
    }

    /**
     * Check if this is a staff user type.
     */
    public function isStaff(): bool
    {
        return $this === self::STAFF;
    }

    /**
     * Check if this is a customer user type.
     */
    public function isCustomer(): bool
    {
        return $this === self::CUSTOMER;
    }
}