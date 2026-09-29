<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__device_tokens')) {
            return;
        }
        if (Schema::hasColumn('dashed__device_tokens', 'access_token_id')) {
            return;
        }

        Schema::table('dashed__device_tokens', function (Blueprint $table): void {
            // Koppelt het device-token aan de Sanctum-sessie die het registreerde.
            // Zo kan de dispatch een uitgelogde (ingetrokken) sessie overslaan.
            $table->unsignedBigInteger('access_token_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('dashed__device_tokens') && Schema::hasColumn('dashed__device_tokens', 'access_token_id')) {
            Schema::table('dashed__device_tokens', function (Blueprint $table): void {
                $table->dropColumn('access_token_id');
            });
        }
    }
};
