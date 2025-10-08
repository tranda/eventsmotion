<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('race_results', 'images')) {
            Schema::table('race_results', function (Blueprint $table) {
                $table->text('images')->nullable()->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('race_results', 'images')) {
            Schema::table('race_results', function (Blueprint $table) {
                $table->dropColumn('images');
            });
        }
    }
};
