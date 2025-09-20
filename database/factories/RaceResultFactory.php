<?php

namespace Database\Factories;

use App\Models\RaceResult;
use App\Models\Discipline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RaceResult>
 */
class RaceResultFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RaceResult::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'race_number' => $this->faker->numberBetween(1, 100),
            'discipline_id' => Discipline::factory(),
            'race_time' => $this->faker->dateTimeBetween('-1 week', '+1 week'),
            'stage' => $this->faker->randomElement(['Round 1', 'Round 2', 'Round 3', 'Semifinal', 'Final']),
            'status' => $this->faker->randomElement(['SCHEDULED', 'IN_PROGRESS', 'FINISHED', 'CANCELLED']),
        ];
    }
}