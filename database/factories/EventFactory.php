<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => $this->faker->sentence(3),
            'location' => $this->faker->city(),
            'year' => $this->faker->year(),
            'status' => $this->faker->randomElement(['ACTIVE', 'INACTIVE', 'COMPLETED']),
            'standard_reserves' => $this->faker->numberBetween(1, 5),
            'standard_min_gende' => $this->faker->numberBetween(0, 2),
            'standard_max_gender' => $this->faker->numberBetween(3, 5),
            'small_reserves' => $this->faker->numberBetween(1, 3),
            'small_min_gender' => $this->faker->numberBetween(0, 1),
            'small_max_gender' => $this->faker->numberBetween(2, 3),
            'race_entries_lock' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'name_entries_lock' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'crew_entries_lock' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
        ];
    }
}