<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hull-aware cadence — column additions only, no data touched.
 *
 * events.hulls_small     comma list of small hull letters, e.g. "D,E,F"
 * events.hulls_standard  comma list of standard hull letters, e.g. "A,B,C"
 *   Empty = fleet rotation disabled for that boat group on this event.
 *
 * race_results.hull      letter assigned by the generator, e.g. "D"
 *   Null when the discipline has no matching fleet or the event has no
 *   hulls configured. Existing rows stay null forever unless a fresh
 *   regenerate rewrites them.
 *
 * Safety: additive only. No UPDATE / DELETE anywhere. Rollback drops
 * the three columns cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'hulls_small')) {
                $table->string('hulls_small', 64)->nullable()->after('lane_count');
            }
            if (!Schema::hasColumn('events', 'hulls_standard')) {
                $table->string('hulls_standard', 64)->nullable()->after('hulls_small');
            }
        });

        Schema::table('race_results', function (Blueprint $table) {
            if (!Schema::hasColumn('race_results', 'hull')) {
                $table->string('hull', 2)->nullable()->after('race_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'hulls_standard')) {
                $table->dropColumn('hulls_standard');
            }
            if (Schema::hasColumn('events', 'hulls_small')) {
                $table->dropColumn('hulls_small');
            }
        });

        Schema::table('race_results', function (Blueprint $table) {
            if (Schema::hasColumn('race_results', 'hull')) {
                $table->dropColumn('hull');
            }
        });
    }
};
