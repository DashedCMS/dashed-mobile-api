<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Retroactieve fix: verwijder bestaande device-tokens die niet aan een
 * Sanctum-sessie gekoppeld zijn (access_token_id NULL). Dat zijn de "oude"
 * registraties van vóór de sessie-koppeling — inclusief toestellen die al
 * uitgelogd waren maar toch nog pushes kregen. Nog-ingelogde apps registreren
 * zichzelf bij de eerstvolgende start automatisch opnieuw (mét access_token_id);
 * uitgelogde toestellen doen dat niet meer en vallen zo definitief stil.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dashed__device_tokens') && Schema::hasColumn('dashed__device_tokens', 'access_token_id')) {
            DB::table('dashed__device_tokens')->whereNull('access_token_id')->delete();
        }
    }

    public function down(): void
    {
        // Onomkeerbaar: verwijderde stale tokens worden niet hersteld.
    }
};
