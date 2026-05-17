<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends race_results so each row can also represent a non-race entry
 * (lunch break, medal ceremony, etc.) without needing a separate table.
 * Breaks live alongside races in the chronological grid and the public
 * schedule view, but carry no discipline / lanes / crews.
 *
 * Idempotent — safe to re-run after a partial failure. Uses raw ALTER TABLE
 * MODIFY for nullability changes so doctrine/dbal is not required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            if (!Schema::hasColumn('race_results', 'entry_type')) {
                $table->string('entry_type', 16)->default('race')->after('id');
            }
            if (!Schema::hasColumn('race_results', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('entry_type')
                    ->constrained('events')->nullOnDelete();
            }
            if (!Schema::hasColumn('race_results', 'duration_seconds')) {
                $table->integer('duration_seconds')->nullable()->after('race_time');
            }
            if (!Schema::hasColumn('race_results', 'label')) {
                $table->string('label')->nullable()->after('duration_seconds');
            }
            if (!Schema::hasColumn('race_results', 'shift_subsequent')) {
                $table->boolean('shift_subsequent')->default(true)->after('label');
            }
        });

        // Add the composite index only if it isn't already present. Different
        // MariaDB/MySQL versions name it differently, so check via INFORMATION_SCHEMA.
        $indexName = 'race_results_event_id_entry_type_index';
        $hasIndex = DB::selectOne(
            "SELECT COUNT(1) AS c FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'race_results'
               AND INDEX_NAME = ?",
            [$indexName],
        );
        if ((int) ($hasIndex->c ?? 0) === 0) {
            DB::statement("CREATE INDEX `$indexName` ON race_results (event_id, entry_type)");
        }

        // Make race_number and discipline_id nullable so break rows can omit them.
        // Raw ALTER MODIFY avoids the doctrine/dbal dependency that ->change() needs.
        DB::statement('ALTER TABLE race_results MODIFY race_number INT NULL');
        DB::statement('ALTER TABLE race_results MODIFY discipline_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            if (Schema::hasColumn('race_results', 'event_id')) {
                try {
                    $table->dropForeign(['event_id']);
                } catch (\Throwable $e) {
                    // FK may not exist in some states; ignore.
                }
            }
        });
        DB::statement('DROP INDEX IF EXISTS race_results_event_id_entry_type_index ON race_results');
        Schema::table('race_results', function (Blueprint $table) {
            $cols = array_filter(
                ['entry_type', 'event_id', 'duration_seconds', 'label', 'shift_subsequent'],
                fn($c) => Schema::hasColumn('race_results', $c),
            );
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
