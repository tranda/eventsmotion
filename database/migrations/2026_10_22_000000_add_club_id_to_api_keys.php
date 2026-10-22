<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an API key to one club. The athletes.* endpoints only work for keys
 * linked to a club, and only for that club's athletes. Additive: existing
 * keys get NULL (no club) and keep working for the races.* endpoints.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('club_id')->nullable()->after('permissions')->index();
        });
    }

    public function down()
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('club_id');
        });
    }
};
