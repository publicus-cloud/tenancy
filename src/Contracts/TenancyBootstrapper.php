<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

interface TenancyBootstrapper
{
    public function start(Tenant $tenant); // todo TenantManager instead of Tenant

    public function end();
}
