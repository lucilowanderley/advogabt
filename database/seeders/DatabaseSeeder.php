<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan migrate --seed
     *
     * Cria o usuário administrador, os modelos de documentos padrão e
     * (fora de produção) dados de demonstração.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
        $this->call(DocumentTemplateSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
