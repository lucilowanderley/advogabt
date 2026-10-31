<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Garante que todas as telas abrem com os dados de demonstração. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->first());

        $pages = [
            '/', '/tarefas', '/tarefas?view=lista&scope=todas', '/tarefas/create', '/tarefas/1', '/tarefas/1/edit',
            '/agenda', '/agenda?mes=2026-01', '/agenda/create', '/agenda/1/edit',
            '/contatos', '/contatos?view=funil', '/contatos/create', '/contatos/1', '/contatos/1/edit',
            '/processos', '/processos?status=todos', '/processos/create', '/processos/1', '/processos/1/edit',
            '/parceiros', '/parceiros/create', '/parceiros/1', '/parceiros/1/edit',
            '/financeiro', '/financeiro?situacao=atrasado', '/financeiro/create', '/financeiro/1/edit',
            '/modelos', '/modelos/create', '/modelos/1', '/modelos/1/edit', '/modelos/1/gerar?contact_id=1&legal_case_id=1',
            '/perfil', '/usuarios', '/usuarios/create', '/buscar?q=maria',
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }
}
