<?php

namespace Database\Factories;

use App\Core\Models\Tenant;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'password' => Hash::make('password'),
            'role' => 4, // Default to Viewer
        ];
    }

    public function administrator(): static
    {
        return $this->state(['role' => 1]);
    }

    public function manager(): static
    {
        return $this->state(['role' => 2]);
    }

    public function developer(): static
    {
        return $this->state(['role' => 3]);
    }

    public function viewer(): static
    {
        return $this->state(['role' => 4]);
    }
}
