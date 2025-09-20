<?php

namespace Database\Factories;

use App\Models\Discipline;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Discipline>
 */
class DisciplineFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Discipline::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'event_id' => Event::factory(),
            'distance' => $this->faker->randomElement([200, 500, 1000, 5000]),
            'age_group' => $this->faker->randomElement(['U16', 'U18', 'Senior', 'Masters']),
            'gender_group' => $this->faker->randomElement(['Men', 'Women', 'Mixed']),
            'boat_group' => $this->faker->randomElement(['K1', 'K2', 'K4', 'C1', 'C2']),
            'status' => $this->faker->randomElement(['ACTIVE', 'INACTIVE']),
        ];
    }
}