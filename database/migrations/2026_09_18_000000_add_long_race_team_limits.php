<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-distance race team limits — column additions only, no data touched.
 *
 * events.long_race_max_small     max teams per long-distance (>1000m) race
 *                                for Small boats. Null/0 = unlimited (one
 *                                Final with all crews).
 * events.long_race_max_standard  same, for Standard boats.
 *
 * When a long-distance discipline has more crews than its boat group's limit,
 * the generator splits it into balanced flights ("Final 1", "Final 2", …).
 *
 * Safety: additive only. No UPDATE / DELETE anywhere. Rollback drops the two
 * columns cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'long_race_max_small')) {
                $table->unsignedSmallInteger('long_race_max_small')->nullable()->after('hulls_standard');
            }
            if (!Schema::hasColumn('events', 'long_race_max_standard')) {
                $table->unsignedSmallInteger('long_race_max_standard')->nullable()->after('long_race_max_small');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'long_race_max_standard')) {
                $table->dropColumn('long_race_max_standard');
            }
            if (Schema::hasColumn('events', 'long_race_max_small')) {
                $table->dropColumn('long_race_max_small');
            }
        });
    }
};
