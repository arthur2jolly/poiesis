<?php

use App\Modules\Dashboard\DashboardModule;
use App\Modules\Document\DocumentModule;
use App\Modules\Example\ExampleModule;
use App\Modules\Kanban\KanbanModule;
use App\Modules\Scrum\ScrumModule;

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

    'example' => ExampleModule::class,
    'document' => DocumentModule::class,
    'dashboard' => DashboardModule::class,
    'kanban' => KanbanModule::class,
    'scrum' => ScrumModule::class,

];
