<?php

namespace Database\Factories;

use App\Core\Models\Project;
use App\Core\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'story_id' => null,
            'titre' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'type' => fake()->randomElement(config('core.item_types')),
            'nature' => fake()->randomElement(config('core.work_natures')),
            'statut' => config('core.default_statut'),
            'priorite' => fake()->randomElement(config('core.priorities')),
            'ordre' => fake()->numberBetween(1, 5),
            'estimation_temps' => fake()->randomElement([30, 60, 120, 240]),
            'tags' => fake()->randomElements(['urgent', 'v2', 'tech-debt', 'hotfix'], 1),
        ];
    }

    public function standalone(): static
    {
        return $this->state(['story_id' => null]);
    }
}
