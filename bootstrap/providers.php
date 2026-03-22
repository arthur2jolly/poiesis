<?php

use App\Core\Providers\CoreServiceProvider;
use App\Core\Providers\ModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    ModuleServiceProvider::class,
];
