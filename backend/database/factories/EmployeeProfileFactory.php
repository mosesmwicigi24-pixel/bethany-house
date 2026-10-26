<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeProfile> */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'job_title'       => 'Tailor',
            'department'      => 'Production',
            'employment_type' => 'permanent',
            'hire_date'       => '2024-03-01',
        ];
    }
}
