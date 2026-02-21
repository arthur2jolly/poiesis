<?php

use App\Core\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'metadata']);

Route::prefix('oauth')->group(function (): void {
    Route::post('/register', [OAuthController::class, 'register']);
    Route::match(['GET', 'POST'], '/authorize', [OAuthController::class, 'authorize']);
    Route::post('/token', [OAuthController::class, 'token']);
    Route::post('/revoke', [OAuthController::class, 'revoke']);
});
