<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Sanctum's personal_access_tokens table. Sanctum only *publishes* this
        // migration (it is not auto-loaded), so consumer apps that install
        // dashed-mobile-api would otherwise be missing it and User::createToken()
        // fails with "Base table or view not found". Guarded so it never clashes
        // with an already-published Sanctum migration (the table is shared with
        // other Sanctum consumers such as the print-queue Printer model).
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally no drop: the table is shared by other Sanctum consumers,
        // so rolling back this package must not remove their token storage.
    }
};
