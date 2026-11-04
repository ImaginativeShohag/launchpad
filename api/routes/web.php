<?php

use App\Http\Controllers\Api\V1\Auth\GoogleCallbackController;
use App\Http\Controllers\Api\V1\Auth\GoogleRedirectController;
use App\Http\Middleware\DeviceId;
use Illuminate\Support\Facades\Route;

// Browser flow: needs the web middleware group (session + state check), so it is
// registered here but keeps the /api/v1 URL the React site and Google are configured with.
Route::prefix('api/v1/auth/google')
    ->middleware([DeviceId::class, 'throttle:google'])
    ->group(function () {
        Route::get('redirect', GoogleRedirectController::class);
        Route::get('callback', GoogleCallbackController::class);
    });
