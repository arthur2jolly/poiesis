<?php

use App\Modules\Dashboard\DashboardModule;
use App\Modules\Document\DocumentModule;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered Modules
    |--------------------------------------------------------------------------
    |
    | List of locally registered modules. Each entry maps a module slug
    | to its class implementing ModuleInterface.
    |
    */

    'document' => DocumentModule::class,
    'dashboard' => DashboardModule::class,

];
