<?php

namespace Database\Seeders;

use App\Models\Discipline;
use App\Models\Event;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DisciplineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get the first event, or create one if none exists
        $event = Event::first();
        if (!$event) {
            $event = Event::create([
                'name' => 'EuroCup Paddling Competition',
                'start_date' => now()->addDays(30),
                'end_date' => now()->addDays(32),
                'location' => 'Sample Location',
                'description' => 'Sample paddling competition event'
            ]);
        }

        // Common paddling disciplines
        $disciplines = [
            // Men's Kayak
            ['distance' => '200m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K2'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K2'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K4'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'K4'],

            // Women's Kayak
            ['distance' => '200m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K1'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K2'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K2'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'K4'],

            // Men's Canoe
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'C1'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'C1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'C2'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'M', 'boat_group' => 'C2'],

            // Women's Canoe
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'C1'],
            ['distance' => '1000m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'C1'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'W', 'boat_group' => 'C2'],

            // Mixed disciplines
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'X', 'boat_group' => 'K2'],
            ['distance' => '500m', 'age_group' => 'Senior', 'gender_group' => 'X', 'boat_group' => 'C2'],

            // Youth categories
            ['distance' => '500m', 'age_group' => 'U18', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'U18', 'gender_group' => 'W', 'boat_group' => 'K1'],
            ['distance' => '1000m', 'age_group' => 'U23', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '1000m', 'age_group' => 'U23', 'gender_group' => 'W', 'boat_group' => 'K1'],

            // Masters categories
            ['distance' => '500m', 'age_group' => 'Masters', 'gender_group' => 'M', 'boat_group' => 'K1'],
            ['distance' => '500m', 'age_group' => 'Masters', 'gender_group' => 'W', 'boat_group' => 'K1'],
        ];

        foreach ($disciplines as $disciplineData) {
            Discipline::create(array_merge($disciplineData, [
                'event_id' => $event->id,
                'status' => 'active'
            ]));
        }
    }
}