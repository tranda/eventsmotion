<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combined / joined categories — column addition only, no data touched.
 *
 * disciplines.combined_with_discipline_id  set on the SECONDARY discipline,
 *   pointing at the HOST it races together with. Host + all normal disciplines
 *   stay null. When set, the generator produces a single shared Final holding
 *   both categories' crews, scored separately per category.
 *
 * Safety: additive only, nullable self-FK (null on delete). Every existing
 * discipline is null → combined logic is inert → behaviour unchanged. Rollback
 * drops the column cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disciplines', function (Blueprint $table) {
            if (!Schema::hasColumn('disciplines', 'combined_with_discipline_id')) {
                $table->unsignedBigInteger('combined_with_discipline_id')->nullable()->after('competition');
                $table->foreign('combined_with_discipline_id')
                    ->references('id')->on('disciplines')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('disciplines', function (Blueprint $table) {
            if (Schema::hasColumn('disciplines', 'combined_with_discipline_id')) {
                $table->dropForeign(['combined_with_discipline_id']);
                $table->dropColumn('combined_with_discipline_id');
            }
        });
    }
};
