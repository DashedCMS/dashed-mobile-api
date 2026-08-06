<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dashed__user_notification_preferences')) {
            return;
        }

        // site_id hoort hier en niet alleen in add_site_id_to_..., want Laravel
        // sorteert migraties alfabetisch op bestandsnaam. Die add-migratie draait
        // dus vóór deze create, valt stil op zijn hasTable-guard en komt nooit
        // meer terug. Op een verse installatie ontbrak de kolom daardoor.
        Schema::create('dashed__user_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('site_id')->nullable()->index();
            $table->string('type');            // notificatietype-key, bv. 'order.placed'
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type', 'site_id'], 'unp_user_type_site_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__user_notification_preferences');
    }
};
