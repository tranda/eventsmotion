<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams cannot be hard-deleted once they carry historical records, so they
 * gain an `active` flag instead. Inactive teams are hidden from noisy
 * listings (dropdowns, registration) but remain visible for reactivation on
 * the Club -> Teams page. Mirrors the existing `active` column on clubs.
 * Default 1 so all existing teams stay active after the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'active')) {
                $table->boolean('active')->default(1)->after('club_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'active')) {
                $table->dropColumn('active');
            }
        });
    }
};
