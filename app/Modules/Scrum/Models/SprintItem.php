<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Models;

use App\Core\Models\Artifact;
use App\Core\Models\Story;
use App\Core\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $sprint_id
 * @property string $artifact_id
 * @property int $position
 * @property Carbon|null $added_at
 * @property-read Sprint $sprint
 * @property-read Artifact|null $artifact
 */
class SprintItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'scrum_sprint_items';

    protected $fillable = ['sprint_id', 'artifact_id', 'position'];

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
        'added_at' => 'datetime',
    ];

    /** @return BelongsTo<Sprint, $this> */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        /** @var Model|null $artifactable */
        $artifactable = $this->artifact?->artifactable;

        $payload = [
            'id' => $this->id,
            'sprint_identifier' => $this->sprint->identifier,
            'position' => $this->position,
            'added_at' => $this->added_at?->toIso8601String(),
            'artifact' => null,
        ];

        if ($artifactable instanceof Story) {
            $payload['artifact'] = [
                'type' => 'story',
                'identifier' => $artifactable->identifier,
                'title' => $artifactable->titre,
                'status' => $artifactable->statut,
                'story_points' => $artifactable->story_points,
                'ready' => $artifactable->ready,
            ];
        } elseif ($artifactable instanceof Task) {
            $payload['artifact'] = [
                'type' => 'task',
                'identifier' => $artifactable->identifier,
                'title' => $artifactable->titre,
                'status' => $artifactable->statut,
                'story_points' => null,
                'ready' => null,
            ];
        }

        return $payload;
    }
}
