<?php

namespace App\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait HasDependencies
{
    public static function bootHasDependencies(): void
    {
        static::deleting(function (self $model) {
            DB::table('item_dependencies')
                ->where(function ($q) use ($model) {
                    $q->where('item_id', $model->id)
                        ->where('item_type', $model->getMorphClass());
                })
                ->orWhere(function ($q) use ($model) {
                    $q->where('depends_on_id', $model->id)
                        ->where('depends_on_type', $model->getMorphClass());
                })
                ->delete();
        });
    }

    /** @return Collection<int, Model> */
    public function blockedBy(): Collection
    {
        $rows = DB::table('item_dependencies')
            ->where('item_id', $this->id)
            ->where('item_type', $this->getMorphClass())
            ->get();

        return $this->resolveItems($rows, 'depends_on_id', 'depends_on_type');
    }

    /** @return Collection<int, Model> */
    public function blocks(): Collection
    {
        $rows = DB::table('item_dependencies')
            ->where('depends_on_id', $this->id)
            ->where('depends_on_type', $this->getMorphClass())
            ->get();

        return $this->resolveItems($rows, 'item_id', 'item_type');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return Collection<int, Model>
     */
    private function resolveItems(\Illuminate\Support\Collection $rows, string $idCol, string $typeCol): Collection
    {
        $grouped = $rows->groupBy($typeCol);
        /** @var Collection<int, Model> $items */
        $items = new Collection;

        foreach ($grouped as $type => $group) {
            $ids = $group->pluck($idCol)->all();
            /** @var class-string<Model> $type */
            $models = $type::whereIn('id', $ids)->get();
            $items = $items->merge($models);
        }

        return $items;
    }
}
