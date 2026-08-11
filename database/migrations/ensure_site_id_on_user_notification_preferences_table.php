<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Reparatiemigratie voor installaties waar `site_id` nooit is toegevoegd.
 *
 * Laravel sorteert migraties alfabetisch op bestandsnaam, dus
 * `add_site_id_to_...` draaide vóór `create_user_notification_preferences_table`.
 * Bestond de tabel op dat moment nog niet, dan viel die add-migratie stil op zijn
 * hasTable-guard én werd hij als uitgevoerd weggeschreven. De create-migratie die
 * daarna liep maakte de tabel zonder `site_id` aan (dat kwam er pas later bij), en
 * geen van beide migraties komt ooit nog terug. Resultaat: een tabel zonder
 * `site_id`, terwijl NotificationPreferences erop filtert → "Unknown column
 * 'site_id' in 'where clause'".
 *
 * Deze migratie sorteert ná de create ('e' > 'c') en is idempotent: op een verse
 * installatie doet hij niets, op een kapotte installatie repareert hij kolom en
 * unique index.
 */
return new class () extends Migration {
    private string $table = 'dashed__user_notification_preferences';
    private string $newUnique = 'unp_user_type_site_unique';

    /**
     * Indexnamen via de schema-builder, niet via information_schema: die tabel
     * bestaat alleen op MySQL en deze migratie draait ook op SQLite (tests).
     *
     * @return array<int, string>
     */
    private function indexNames(): array
    {
        return array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes($this->table),
        );
    }

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        if (! Schema::hasColumn($this->table, 'site_id')) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->string('site_id')->nullable()->after('user_id')->index();
            });
        }

        $indexes = $this->indexNames();
        $oldUnique = $this->table . '_user_id_type_unique';

        // De oude unique (user_id, type) blokkeert dezelfde voorkeur op een tweede
        // site en moet dus wijken voor (user_id, type, site_id).
        if (in_array($oldUnique, $indexes, true)) {
            Schema::table($this->table, function (Blueprint $table) use ($oldUnique): void {
                $table->dropUnique($oldUnique);
            });
        }

        if (! in_array($this->newUnique, $indexes, true)) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->unique(['user_id', 'type', 'site_id'], $this->newUnique);
            });
        }
    }

    public function down(): void
    {
        // Niets: `site_id` en de unique index horen bij de create-migratie, die ze
        // bij een rollback samen met de tabel opruimt. Hier iets droppen zou de
        // kolom ook weghalen op installaties waar hij altijd correct was.
    }
};
