<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dashed__user_order_origin_preferences')) {
            return;
        }

        Schema::create('dashed__user_order_origin_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('order_origin');    // 'own', 'pos', 'Bol', 'etsy', ...
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'order_origin'], 'uniq_user_order_origin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__user_order_origin_preferences');
    }
};
