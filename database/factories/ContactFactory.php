<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'person_type' => 'PF',
            'category' => 'cliente',
            'crm_stage' => 'cliente',
            'email' => fake()->safeEmail(),
            'phone' => '(11) 9'.fake()->numerify('####-####'),
            'city' => fake()->city(),
            'state' => 'SP',
        ];
    }
}
