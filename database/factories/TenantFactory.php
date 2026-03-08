<?php

namespace Database\Factories;

use App\Core\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'slug' => Str::slug($name).'-'.Str::random(4),
            'name' => $name,
            'is_active' => true,
        ];
    }
}
