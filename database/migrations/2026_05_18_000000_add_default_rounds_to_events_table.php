<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event default number of rounds, used by the generator when a discipline's
 * registered crew count is <= the event's lane count (everyone fits, no need
 * for heats/repechages/semis). Defaults to 3 to match IDBF's ROUNDS_xL plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'default_rounds')) {
                $table->unsignedTinyInteger('default_rounds')->default(3)->after('lane_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'default_rounds')) {
                $table->dropColumn('default_rounds');
            }
        });
    }
};
