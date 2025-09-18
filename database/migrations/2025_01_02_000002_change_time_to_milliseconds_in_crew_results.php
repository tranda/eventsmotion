<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crew_results', function (Blueprint $table) {
            $table->integer('time_ms')->nullable()->after('position'); // Time in milliseconds
            $table->dropColumn('time'); // Remove old string column
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crew_results', function (Blueprint $table) {
            $table->string('time')->nullable()->after('position'); // Restore string column
            $table->dropColumn('time_ms'); // Remove milliseconds column
        });
    }
};