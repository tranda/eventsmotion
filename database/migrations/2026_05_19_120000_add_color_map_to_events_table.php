<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event colour map used by the Grid to render coloured chips on each
 * race row (boat group, age group, stage type, gender). Nullable —
 * absence means "use the frontend's default palette".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'color_map')) {
                $table->json('color_map')->nullable()->after('min_crews_per_race');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'color_map')) {
                $table->dropColumn('color_map');
            }
        });
    }
};
