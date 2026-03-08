<?php

namespace Database\Factories;

use App\Core\Models\Project;
use App\Core\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('????-???')),
            'titre' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'modules' => [],
        ];
    }
}
