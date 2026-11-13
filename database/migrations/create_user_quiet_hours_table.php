<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dashed__user_quiet_hours')) {
            return;
        }

        Schema::create('dashed__user_quiet_hours', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            // Lokale begin-/eindtijd als "HH:MM". Eind <= begin betekent dat het
            // venster over middernacht heen loopt (bv. 22:00–07:00).
            $table->string('start_time', 5)->default('22:00');
            $table->string('end_time', 5)->default('07:00');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__user_quiet_hours');
    }
};
