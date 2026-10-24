<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimeEntry> */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'user_id'            => User::factory(),
            'outlet_id'          => Outlet::factory(),
            'clock_in_at'        => now()->subHours(2),
            'clock_in_latitude'  => -1.2921,
            'clock_in_longitude' => 36.8219,
            'clock_in_method'    => 'gps',
            'status'             => 'active',
        ];
    }

    public function completed(int $workedMinutes = 480): static
    {
        return $this->state(fn () => [
            'clock_in_at'    => now()->subMinutes($workedMinutes + 30),
            'clock_out_at'   => now()->subMinutes(30),
            'worked_minutes' => $workedMinutes,
            'total_break_minutes' => 0,
            'status'         => 'completed',
        ]);
    }
}
