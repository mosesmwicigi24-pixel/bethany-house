<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shift> */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'user_id'   => User::factory(),
            'date'      => now()->addDay()->toDateString(),
            'starts_at' => '08:00',
            'ends_at'   => '17:00',
            'status'    => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published', 'published_at' => now()]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
