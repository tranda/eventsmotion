<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-race override of the auto-derived progression rule (the "where do
 * these crews go next" one-liner shown in the Grid and printed under each
 * race in the PDF start list). NULL = use the auto rule; non-empty string
 * = admin override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            if (!Schema::hasColumn('race_results', 'progression_note')) {
                $table->string('progression_note', 500)->nullable()->after('label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            if (Schema::hasColumn('race_results', 'progression_note')) {
                $table->dropColumn('progression_note');
            }
        });
    }
};
