<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Dashed\DashedMobileApi\Http\Controllers\Api\V1\AppPageController;

// Magic-link voor app-pagina's: éénmalig token (uitgegeven via POST
// /api/v1/app-pages/{key}/open) → web-sessie inloggen + doorsturen naar de
// admin-pagina van de module. Expliciet in de 'web'-middlewaregroep, zodat de
// sessie/cookies werken.
Route::middleware('web')->group(function (): void {
    Route::get('mobile-app-page/{token}', [AppPageController::class, 'visit']);
});
