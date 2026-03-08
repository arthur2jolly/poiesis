<?php

namespace App\Core\Models\Concerns;

use App\Core\Models\Tenant;
use App\Core\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                /** @var TenantManager $manager */
                $manager = app(TenantManager::class);
                if ($manager->hasTenant()) {
                    $builder->where($model->getTable().'.tenant_id', $manager->getTenant()->id);
                }
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
                /** @var TenantManager $manager */
                $manager = app(TenantManager::class);
                if ($manager->hasTenant()) {
                    $model->tenant_id = $manager->getTenant()->id;
                }
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
