<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Models;

use App\Core\Models\Artifact;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $sprint_id
 * @property string $artifact_id
 * @property int $position
 * @property Carbon $added_at
 * @property-read Sprint $sprint
 * @property-read Artifact $artifact
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
}
