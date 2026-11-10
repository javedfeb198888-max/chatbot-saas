<?php

namespace Database\Factories;

use App\Models\Bot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bot> */
class BotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Assistant',
        ];
    }
}
