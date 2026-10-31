<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\LegalCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegalCase> */
class LegalCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Ação '.fake()->words(3, true),
            'number' => fake()->numerify('#######-##.####.8.26.####'),
            'contact_id' => Contact::factory(),
            'area' => 'civel',
            'phase' => 'conhecimento',
            'status' => 'ativo',
            'client_role' => 'autor',
        ];
    }
}
