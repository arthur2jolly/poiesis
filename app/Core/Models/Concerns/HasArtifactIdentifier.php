<?php

namespace App\Core\Models\Concerns;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;

trait HasArtifactIdentifier
{
    public static function bootHasArtifactIdentifier(): void
    {
        static::deleting(function (self $model) {
            $model->artifact()->delete();
        });

        static::created(function (self $model) {
            DB::transaction(function () use ($model) {
                $projectId = $model->getProjectIdForArtifact();
                $projectCode = $model->getProjectCodeForArtifact();

                $maxSequence = Artifact::where('project_id', $projectId)
                    ->lockForUpdate()
                    ->max('sequence_number');

                $nextSequence = ($maxSequence ?? 0) + 1;
                $identifier = $projectCode.'-'.$nextSequence;

                Artifact::create([
                    'project_id' => $projectId,
                    'tenant_id' => $model->getTenantIdForArtifact(),
                    'identifier' => $identifier,
                    'sequence_number' => $nextSequence,
                    'artifactable_id' => $model->id,
                    'artifactable_type' => $model->getMorphClass(),
                ]);
            });
        });
    }

    /** @return MorphOne<Artifact, $this> */
    public function artifact(): MorphOne
    {
        return $this->morphOne(Artifact::class, 'artifactable');
    }

    public function getIdentifierAttribute(): ?string
    {
        $artifact = $this->artifact()->first();

        return $artifact?->identifier;
    }

    protected function getProjectIdForArtifact(): string
    {
        if (property_exists($this, 'project_id') && $this->project_id) {
            /** @var string */
            return $this->project_id;
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (method_exists($this, 'project')) {
            /** @var Project|null $project */
            $project = $this->project; // @phpstan-ignore-line
            if ($project !== null) {
                return $project->id;
            }
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (method_exists($this, 'epic')) {
            /** @var Epic|null $epic */
            $epic = $this->epic; // @phpstan-ignore-line
            if ($epic !== null) {
                return $epic->project_id;
            }
        }

        throw new \RuntimeException('Cannot resolve project_id for artifact generation.');
    }

    protected function getTenantIdForArtifact(): string
    {
        $projectId = $this->getProjectIdForArtifact();

        /** @var string */
        return Project::withoutGlobalScope('tenant')->where('id', $projectId)->value('tenant_id');
    }

    protected function getProjectCodeForArtifact(): string
    {
        if (property_exists($this, 'project_id') && $this->project_id) {
            /** @var string */
            return Project::where('id', $this->project_id)->value('code');
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (method_exists($this, 'project')) {
            /** @var Project|null $project */
            $project = $this->project; // @phpstan-ignore-line
            if ($project !== null) {
                return $project->code;
            }
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (method_exists($this, 'epic')) {
            /** @var Epic|null $epic */
            $epic = $this->epic; // @phpstan-ignore-line
            if ($epic !== null) {
                return $epic->project->code;
            }
        }

        throw new \RuntimeException('Cannot resolve project code for artifact generation.');
    }
}
