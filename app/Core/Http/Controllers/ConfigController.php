<?php

namespace App\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'item_types' => config('core.item_types'),
            'priorities' => config('core.priorities'),
            'statuts' => config('core.statuts'),
            'work_natures' => config('core.work_natures'),
            'project_roles' => config('core.project_roles'),
            'oauth_scopes' => config('core.oauth_scopes'),
        ]);
    }
}
