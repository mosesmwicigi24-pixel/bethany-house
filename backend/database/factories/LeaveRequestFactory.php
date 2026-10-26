<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'leave_type_id' => fn () => LeaveType::where('code', 'annual')->value('id'),
            'starts_on'     => now()->addDays(7)->toDateString(),
            'ends_on'       => now()->addDays(8)->toDateString(),
            'days'          => 2,
            'status'        => 'pending',
        ];
    }
}
