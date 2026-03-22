<?php

declare(strict_types=1);

namespace App\Modules\Document\Models;

use App\Core\Models\Concerns\HasArtifactIdentifier;
use App\Core\Models\Project;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string $title
 * @property string $summary
 * @property string $content
 * @property string $type
 * @property string $status
 * @property array<int, string>|null $tags
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 */
class Document extends Model
{
    use HasArtifactIdentifier, HasUuids;

    protected $fillable = [
        'project_id', 'title', 'summary', 'content',
        'type', 'status', 'tags',
    ];

    protected $attributes = [
        'summary' => '',
        'content' => '',
        'type' => 'reference',
        'status' => 'draft',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public const TYPES = ['spec', 'note', 'research', 'reference', 'other'];

    public const STATUSES = ['draft', 'published', 'archived'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getProjectCodeAttribute(): string
    {
        return $this->project->code;
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        return [
            'identifier' => $this->identifier,
            'title' => $this->title,
            'summary' => $this->summary,
            'type' => $this->type,
            'status' => $this->status,
            'tags' => $this->tags ?? [],
            'content_length' => mb_strlen($this->content ?? ''),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
