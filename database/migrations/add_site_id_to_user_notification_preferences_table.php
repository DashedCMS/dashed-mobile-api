<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private string $table = 'dashed__user_notification_preferences';
    private string $newUnique = 'unp_user_type_site_unique';

    private function indexExists(string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $this->table)
            ->where('index_name', $index)
            ->exists();
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

        // Oude unique (user_id, type) vervangen door (user_id, type, site_id) met
        // een korte naam (anders > 64 tekens → MySQL-fout). Idempotent.
        $oldUnique = $this->table . '_user_id_type_unique';
        if ($this->indexExists($oldUnique)) {
            Schema::table($this->table, function (Blueprint $table) use ($oldUnique): void {
                $table->dropUnique($oldUnique);
            });
        }

        if (! $this->indexExists($this->newUnique)) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->unique(['user_id', 'type', 'site_id'], $this->newUnique);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        if ($this->indexExists($this->newUnique)) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->dropUnique($this->newUnique);
            });
        }

        if (! $this->indexExists($this->table . '_user_id_type_unique')) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->unique(['user_id', 'type']);
            });
        }

        if (Schema::hasColumn($this->table, 'site_id')) {
            Schema::table($this->table, function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }
    }
};
