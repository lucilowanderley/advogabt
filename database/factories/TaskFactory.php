<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'status' => 'pendente',
            'priority' => 'normal',
            'points' => 10,
            'due_at' => now()->addDays(3)->setTime(18, 0),
        ];
    }
}
