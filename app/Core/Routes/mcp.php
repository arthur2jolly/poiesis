<?php

use App\Core\Mcp\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.bearer')->group(function () {
    Route::post('/mcp', [McpController::class, 'handle']);
    Route::get('/mcp', [McpController::class, 'stream']);
});
