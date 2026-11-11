<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badge front layout per event: 'classic' (EDBF Nationals: banner bar,
 * footer band, ribbon on the right) or 'eurocup' (Euro Cup 2023: artwork
 * header and bottom strip, category ribbon, big names, centred club).
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('badge_templates', 'layout')) {
            Schema::table('badge_templates', function (Blueprint $table) {
                $table->string('layout', 20)->default('classic')->after('size');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('badge_templates', 'layout')) {
            Schema::table('badge_templates', function (Blueprint $table) {
                $table->dropColumn('layout');
            });
        }
    }
};
