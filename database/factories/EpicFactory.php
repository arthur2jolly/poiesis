<?php

namespace Database\Factories;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Epic> */
class EpicFactory extends Factory
{
    protected $model = Epic::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'titre' => fake()->sentence(4),
            'description' => fake()->paragraph(),
        ];
    }
}
