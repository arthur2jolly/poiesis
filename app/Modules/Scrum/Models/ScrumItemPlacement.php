<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $sprint_item_id
 * @property string $column_id
 * @property int $position
 * @property Carbon|null $updated_at
 * @property-read SprintItem $sprintItem
 * @property-read ScrumColumn $column
 */
class ScrumItemPlacement extends Model
{
    use HasUuids;

    protected $table = 'scrum_item_placements';

    /** Disable Eloquent default timestamps; only updated_at is managed explicitly. */
    public $timestamps = false;

    protected $fillable = ['sprint_item_id', 'column_id', 'position'];

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ScrumItemPlacement $placement): void {
            $placement->updated_at = Carbon::now();
        });
    }

    /** @return BelongsTo<SprintItem, $this> */
    public function sprintItem(): BelongsTo
    {
        return $this->belongsTo(SprintItem::class, 'sprint_item_id');
    }

    /** @return BelongsTo<ScrumColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(ScrumColumn::class, 'column_id');
    }

    /** @return array<string, mixed> */
    public function format(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sprint_item' => $this->sprintItem->format(),
        ];
    }
}
