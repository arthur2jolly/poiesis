<?php

namespace Database\Factories;

use App\Core\Models\Epic;
use App\Core\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Story> */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    public function definition(): array
    {
        return [
            'epic_id' => Epic::factory(),
            'titre' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'type' => fake()->randomElement(config('core.item_types')),
            'nature' => fake()->randomElement(config('core.work_natures')),
            'statut' => config('core.default_statut'),
            'priorite' => fake()->randomElement(config('core.priorities')),
            'ordre' => fake()->numberBetween(1, 10),
            'story_points' => fake()->randomElement([1, 2, 3, 5, 8, 13]),
            'tags' => fake()->randomElements(['api', 'auth', 'db', 'ui', 'perf'], 2),
        ];
    }
}
