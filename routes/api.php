<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\AuthController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DeviceController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DashboardController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\CapabilitiesController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\NotificationPreferenceController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:10,1');
    Route::get('capabilities', [CapabilitiesController::class, 'index'])->middleware(['auth:sanctum', 'mobile.site']);

    Route::middleware(['auth:sanctum', 'mobile.site'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('ability:dashboard.read');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('ability:devices.write');

        // Per-gebruiker notificatievoorkeuren + zelftest.
        Route::get('notifications/preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update']);
        Route::post('notifications/test', [NotificationPreferenceController::class, 'test']);
    });
});
