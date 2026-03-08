<?php

namespace App\Core\Services;

use App\Core\Models\Tenant;

class TenantManager
{
    private ?Tenant $tenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new \RuntimeException('No tenant resolved for this request.');
        }

        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
