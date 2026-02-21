<?php

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;

describe('Artifact identifier concurrency (CL4)', function () {

    it('generates unique sequential identifiers for 10 rapid story creations', function () {
        $project = Project::factory()->create(['code' => 'CONC']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        $stories = [];
        for ($i = 0; $i < 10; $i++) {
            $stories[] = Story::factory()->create(['epic_id' => $epic->id]);
        }

        // Collect all artifact identifiers
        $identifiers = Artifact::where('project_id', $project->id)
            ->orderBy('sequence_number')
            ->pluck('identifier')
            ->all();

        // The epic gets identifier CONC-1, then stories get CONC-2 through CONC-11
        expect($identifiers)->toHaveCount(11);
        expect(array_unique($identifiers))->toHaveCount(11);

        // Verify sequential numbering with no gaps
        $sequences = Artifact::where('project_id', $project->id)
            ->orderBy('sequence_number')
            ->pluck('sequence_number')
            ->all();

        expect($sequences)->toBe(range(1, 11));
    });

    it('generates unique identifiers across mixed entity types', function () {
        $project = Project::factory()->create(['code' => 'MIX']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        // Create mix of stories and tasks rapidly
        Story::factory()->create(['epic_id' => $epic->id]);
        Task::factory()->standalone()->create(['project_id' => $project->id]);
        Story::factory()->create(['epic_id' => $epic->id]);
        Task::factory()->standalone()->create(['project_id' => $project->id]);
        Story::factory()->create(['epic_id' => $epic->id]);

        $identifiers = Artifact::where('project_id', $project->id)
            ->orderBy('sequence_number')
            ->pluck('identifier')
            ->all();

        // 1 epic + 3 stories + 2 tasks = 6 artifacts
        expect($identifiers)->toHaveCount(6);
        expect(array_unique($identifiers))->toHaveCount(6);

        // All follow MIX-N pattern
        foreach ($identifiers as $id) {
            expect($id)->toMatch('/^MIX-\d+$/');
        }

        // No gaps in sequence
        $sequences = Artifact::where('project_id', $project->id)
            ->orderBy('sequence_number')
            ->pluck('sequence_number')
            ->all();

        expect($sequences)->toBe(range(1, 6));
    });

    it('maintains separate sequences per project', function () {
        $projectA = Project::factory()->create(['code' => 'PRJA']);
        $projectB = Project::factory()->create(['code' => 'PRJB']);
        $epicA = Epic::factory()->create(['project_id' => $projectA->id]);
        $epicB = Epic::factory()->create(['project_id' => $projectB->id]);

        Story::factory()->count(3)->create(['epic_id' => $epicA->id]);
        Story::factory()->count(2)->create(['epic_id' => $epicB->id]);

        $seqA = Artifact::where('project_id', $projectA->id)
            ->orderBy('sequence_number')
            ->pluck('sequence_number')
            ->all();

        $seqB = Artifact::where('project_id', $projectB->id)
            ->orderBy('sequence_number')
            ->pluck('sequence_number')
            ->all();

        // Project A: 1 epic + 3 stories = 4
        expect($seqA)->toBe(range(1, 4));
        // Project B: 1 epic + 2 stories = 3
        expect($seqB)->toBe(range(1, 3));
    });

    /**
     * Note: True multi-process concurrency testing requires external tooling
     * (Apache Bench, k6) and should be done in a staging environment with
     * MariaDB, where SELECT ... FOR UPDATE provides proper row-level locking.
     * SQLite used in tests does not support row-level locking.
     */
});
