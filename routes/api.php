<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\AuthController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DeviceController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\SearchController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\CopilotController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DashboardController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\AppVersionController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\CapabilitiesController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DailySummaryController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\NotificationInboxController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\NotificationPreferenceController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:10,1');
    Route::get('capabilities', [CapabilitiesController::class, 'index'])->middleware(['auth:sanctum', 'mobile.site']);

    Route::middleware(['auth:sanctum', 'mobile.site'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('app-version', [AppVersionController::class, 'show']);

        // Globaal zoeken over alle modules. Geen specifieke ability: elke provider
        // scope't zelf op site (+ eigen recht); de route zit al achter auth.
        Route::get('search', [SearchController::class, 'index']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('ability:dashboard.read');
        // Dag-overzicht: alle summary-secties voor één dag (default gisteren).
        Route::get('daily-summary', [DailySummaryController::class, 'show'])->middleware('ability:dashboard.read');
        Route::post('ai/copilot', [CopilotController::class, 'ask'])->middleware('ability:dashboard.read');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('ability:devices.write');

        // Per-gebruiker notificatievoorkeuren + zelftest.
        Route::get('notifications/preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update']);
        Route::post('notifications/test', [NotificationPreferenceController::class, 'test']);

        // Persisted notificatie-inbox (per gebruiker + actieve site).
        Route::get('notifications/unread-count', [NotificationInboxController::class, 'unreadCount']);
        Route::get('notifications', [NotificationInboxController::class, 'index']);
        Route::post('notifications/read-all', [NotificationInboxController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationInboxController::class, 'read'])->whereNumber('id');
    });
});
