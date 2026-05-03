<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('discipline_progressions', function (Blueprint $table) {
            // text + array cast at the model level — keeps MariaDB happy without
            // requiring a JSON column type.
            $table->text('custom_stages')->nullable()->after('race_plan_code');
        });
    }

    public function down()
    {
        Schema::table('discipline_progressions', function (Blueprint $table) {
            $table->dropColumn('custom_stages');
        });
    }
};
