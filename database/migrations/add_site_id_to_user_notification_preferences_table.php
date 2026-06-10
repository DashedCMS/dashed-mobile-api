<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__user_notification_preferences')) {
            return;
        }

        if (! Schema::hasColumn('dashed__user_notification_preferences', 'site_id')) {
            Schema::table('dashed__user_notification_preferences', function (Blueprint $table): void {
                $table->string('site_id')->nullable()->after('user_id')->index();
            });
        }

        // Voorkeuren gelden per site: uniek op (user_id, type, site_id).
        Schema::table('dashed__user_notification_preferences', function (Blueprint $table): void {
            try {
                $table->dropUnique(['user_id', 'type']);
            } catch (\Throwable $e) {
                // index bestond al niet meer — negeren
            }
        });

        Schema::table('dashed__user_notification_preferences', function (Blueprint $table): void {
            try {
                $table->unique(['user_id', 'type', 'site_id']);
            } catch (\Throwable $e) {
                // unique bestond al — negeren
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__user_notification_preferences')) {
            return;
        }

        Schema::table('dashed__user_notification_preferences', function (Blueprint $table): void {
            try {
                $table->dropUnique(['user_id', 'type', 'site_id']);
            } catch (\Throwable $e) {
            }
            $table->unique(['user_id', 'type']);
            $table->dropColumn('site_id');
        });
    }
};
