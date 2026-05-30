<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, event-scoped snapshots of three orthogonal slices of the schedule
 * builder state:
 *   - setup      — event params + days + blocks + color_map
 *   - plan_seeds — discipline progressions + crew seed_numbers
 *   - grid_day   — races + crew_results + breaks for ONE day
 *
 * Operator can save a named snapshot before a risky operation, then
 * restore later. Payload is JSON (longText for MariaDB compat).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedule_snapshots')) return;

        Schema::create('schedule_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);    // 'setup' | 'plan_seeds' | 'grid_day'
            $table->date('day')->nullable();   // only for category = 'grid_day'
            $table->string('name', 200);
            $table->longText('payload');       // JSON; cast on the model
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'category', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_snapshots');
    }
};
