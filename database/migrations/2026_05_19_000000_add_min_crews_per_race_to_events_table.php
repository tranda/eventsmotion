<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event minimum number of crews required for a discipline to run.
 * After every crew-registration import (or any operation that touches
 * crews), each discipline's status is recomputed: crew_count >= threshold
 * → active, else → inactive. Default 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'min_crews_per_race')) {
                $table->unsignedTinyInteger('min_crews_per_race')->default(3)->after('default_rounds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'min_crews_per_race')) {
                $table->dropColumn('min_crews_per_race');
            }
        });
    }
};
