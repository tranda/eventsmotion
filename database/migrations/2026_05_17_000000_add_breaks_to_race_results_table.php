<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends race_results so each row can also represent a non-race entry
 * (lunch break, medal ceremony, etc.) without needing a separate table.
 * Breaks live alongside races in the chronological grid and the public
 * schedule view, but carry no discipline / lanes / crews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            // 'race' for normal scheduled races, 'break' for pauses/ceremonies.
            $table->string('entry_type', 16)->default('race')->after('id');
            // For breaks: direct link to event (since discipline is null).
            $table->foreignId('event_id')->nullable()->after('entry_type')
                ->constrained('events')->nullOnDelete();
            // For breaks: duration in seconds.
            $table->integer('duration_seconds')->nullable()->after('race_time');
            // For breaks: free-form label (e.g. "Lunch", "Medal Ceremony").
            $table->string('label')->nullable()->after('duration_seconds');
            // For breaks: true = push later races back by duration (shift mode);
            // false = parallel mode (sits alongside racing without shifting).
            $table->boolean('shift_subsequent')->default(true)->after('label');

            $table->index(['event_id', 'entry_type']);
        });

        // race_number must allow nulls for break rows.
        Schema::table('race_results', function (Blueprint $table) {
            $table->integer('race_number')->nullable()->change();
        });

        // discipline_id must allow nulls for break rows.
        // (Wrapped in try/catch so it's idempotent across DB drivers without doctrine/dbal warnings.)
        try {
            Schema::table('race_results', function (Blueprint $table) {
                $table->foreignId('discipline_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Fallback raw SQL for MariaDB if doctrine/dbal is missing.
            \DB::statement('ALTER TABLE race_results MODIFY discipline_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['event_id', 'entry_type']);
            $table->dropColumn(['entry_type', 'event_id', 'duration_seconds', 'label', 'shift_subsequent']);
        });
    }
};
