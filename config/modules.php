<?php

use App\Modules\Example\ExampleModule;

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

];
