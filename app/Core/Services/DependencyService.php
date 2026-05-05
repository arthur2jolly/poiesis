<?php

namespace App\Core\Services;

use App\Core\Models\Story;
use App\Core\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DependencyService
{
    private const MAX_DEPTH = 50;

    public function addDependency(Model $blockedItem, Model $blockingItem): void
    {
        if ($blockedItem->id === $blockingItem->id && $blockedItem->getMorphClass() === $blockingItem->getMorphClass()) {
            throw ValidationException::withMessages([
                'dependency' => ['An item cannot depend on itself.'],
            ]);
        }

        $exists = DB::table('item_dependencies')
            ->where('item_id', $blockedItem->id)
            ->where('item_type', $blockedItem->getMorphClass())
            ->where('depends_on_id', $blockingItem->id)
            ->where('depends_on_type', $blockingItem->getMorphClass())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'dependency' => ['This dependency already exists.'],
            ]);
        }

        if ($this->wouldCreateCycle($blockedItem, $blockingItem)) {
            throw ValidationException::withMessages([
                'dependency' => ['This dependency would create a circular reference.'],
            ]);
        }

        DB::table('item_dependencies')->insert([
            'id' => (string) Str::uuid7(),
            'item_id' => $blockedItem->id,
            'item_type' => $blockedItem->getMorphClass(),
            'depends_on_id' => $blockingItem->id,
            'depends_on_type' => $blockingItem->getMorphClass(),
            'created_at' => now(),
        ]);
    }

    public function removeDependency(Model $blockedItem, Model $blockingItem): void
    {
        DB::table('item_dependencies')
            ->where('item_id', $blockedItem->id)
            ->where('item_type', $blockedItem->getMorphClass())
            ->where('depends_on_id', $blockingItem->id)
            ->where('depends_on_type', $blockingItem->getMorphClass())
            ->delete();
    }

    /**
     * @return array{blocked_by: array, blocks: array}
     */
    public function getDependencies(Model $item): array
    {
        $blockedByRows = DB::table('item_dependencies')
            ->where('item_id', $item->id)
            ->where('item_type', $item->getMorphClass())
            ->get();

        $blocksRows = DB::table('item_dependencies')
            ->where('depends_on_id', $item->id)
            ->where('depends_on_type', $item->getMorphClass())
            ->get();

        return [
            'blocked_by' => $this->resolveModels($blockedByRows, 'depends_on_id', 'depends_on_type'),
            'blocks' => $this->resolveModels($blocksRows, 'item_id', 'item_type'),
        ];
    }

    private function wouldCreateCycle(Model $blockedItem, Model $blockingItem): bool
    {
        // If adding blockedItem depends_on blockingItem, check that
        // blockingItem is not already (transitively) blocked by blockedItem
        return $this->isTransitivelyBlockedBy(
            $blockingItem->id,
            $blockingItem->getMorphClass(),
            $blockedItem->id,
            $blockedItem->getMorphClass(),
            0
        );
    }

    private function isTransitivelyBlockedBy(
        string $itemId,
        string $itemType,
        string $targetId,
        string $targetType,
        int $depth
    ): bool {
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        $dependencies = DB::table('item_dependencies')
            ->where('item_id', $itemId)
            ->where('item_type', $itemType)
            ->get();

        foreach ($dependencies as $dep) {
            if ($dep->depends_on_id === $targetId && $dep->depends_on_type === $targetType) {
                return true;
            }

            if ($this->isTransitivelyBlockedBy($dep->depends_on_id, $dep->depends_on_type, $targetId, $targetType, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function resolveModels($rows, string $idCol, string $typeCol): array
    {
        $grouped = $rows->groupBy($typeCol);
        $result = [];

        foreach ($grouped as $type => $group) {
            $ids = $group->pluck($idCol)->all();
            $models = $this->loadByType((string) $type, $ids);
            $result = array_merge($result, $models);
        }

        return $result;
    }

    /**
     * Load models by morph type with the current tenant scope applied.
     *
     * Story/Task tables have no tenant_id column. We rely on the existing
     * BelongsToTenant scope of Project (and Epic via project) so a blocker
     * resolved across tenants is silently dropped instead of leaking
     * identifier/status to the response.
     *
     * @param  array<int, string>  $ids
     * @return array<int, Model>
     */
    private function loadByType(string $type, array $ids): array
    {
        return match ($type) {
            Story::class => Story::whereIn('id', $ids)
                ->whereHas('epic.project')
                ->get()
                ->all(),
            Task::class => Task::whereIn('id', $ids)
                ->whereHas('project')
                ->get()
                ->all(),
            default => $type::whereIn('id', $ids)->get()->all(),
        };
    }
}
