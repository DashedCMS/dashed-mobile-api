<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\AuthController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DeviceController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\DashboardController;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\ConversationController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'mobile.site'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('conversations', [ConversationController::class, 'index'])->middleware('ability:chat.read');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])
            ->middleware(['ability:chat.read', 'throttle:60,1']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])
            ->middleware('ability:chat.reply');
        Route::post('conversations/{conversation}/take-over', [ConversationController::class, 'takeOver'])
            ->middleware('ability:chat.takeover');
        Route::post('conversations/{conversation}/release', [ConversationController::class, 'release'])
            ->middleware('ability:chat.takeover');

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('ability:dashboard.read');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('ability:devices.write');
    });
});
