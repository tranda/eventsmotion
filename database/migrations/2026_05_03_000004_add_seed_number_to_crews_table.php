<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('crews', function (Blueprint $table) {
            $table->unsignedSmallInteger('seed_number')->nullable()->after('discipline_id');
            $table->index(['discipline_id', 'seed_number']);
        });
    }

    public function down()
    {
        Schema::table('crews', function (Blueprint $table) {
            $table->dropIndex(['discipline_id', 'seed_number']);
            $table->dropColumn('seed_number');
        });
    }
};
