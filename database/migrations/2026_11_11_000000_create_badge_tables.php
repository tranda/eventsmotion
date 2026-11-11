<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event accreditation badges.
 *
 * badge_templates: one row per event with the print settings (banner text,
 * colours, logo, background, per-type colour/label). The layout itself is
 * fixed in code; the size (small 54x85.6 mm / large 100x145 mm, both
 * portrait) is chosen on the template and can be overridden per download.
 * Badges are always two-sided: the PDF alternates front, back.
 *
 * badge_people: people who get a badge but are not athletes on a crew
 * (volunteers, VIPs, media, extra staff). Added by hand per event.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('badge_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->onDelete('cascade');
            $table->string('size', 10)->default('small');      // small | large
            $table->string('banner_text', 60)->nullable();      // defaults to event name
            $table->string('banner_color', 7)->default('#0072BC');
            $table->string('footer_color', 7)->default('#F3F1DC');
            $table->string('logo')->nullable();                 // file in storage/app/badges/{event}
            $table->string('background')->nullable();
            $table->string('back_background')->nullable();      // artwork for the back side
            $table->text('back_text')->nullable();              // free text on the back (venue, phone numbers...)
            $table->unsignedTinyInteger('bleed_mm')->default(0);
            $table->json('fields')->nullable();                 // which fields show
            $table->json('types')->nullable();                  // per-type label, colour, accessible areas
            $table->json('areas')->nullable();                  // [{no: 1, name: "Boat marshalling"}, ...]
            $table->timestamps();
        });

        Schema::create('badge_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('type', 20);                         // volunteer | vip | media | coach | official | support
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('organisation', 150)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('badge_people');
        Schema::dropIfExists('badge_templates');
    }
};
