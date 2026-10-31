<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DocumentTemplate;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Dra. Teste', 'oab' => 'SP 1']));
    }

    public function test_contact_and_case_crud(): void
    {
        $this->post('/contatos', [
            'name' => 'Cliente Novo', 'person_type' => 'PF', 'category' => 'cliente', 'crm_stage' => 'lead',
            'document' => '111.222.333-44', 'state' => 'SP',
        ])->assertRedirect();
        $contact = Contact::firstOrFail();

        $this->patchJson("/contatos/{$contact->id}/etapa", ['crm_stage' => 'cliente'])->assertOk();
        $this->assertSame('cliente', $contact->fresh()->crm_stage);

        $this->post('/processos', [
            'title' => 'Ação de cobrança', 'contact_id' => $contact->id, 'client_role' => 'autor', 'area' => 'civel',
            'phase' => 'conhecimento', 'status' => 'ativo', 'claim_value' => '12.345,67',
        ])->assertRedirect();
        $case = LegalCase::firstOrFail();
        $this->assertEquals(12345.67, (float) $case->claim_value);

        $this->post("/processos/{$case->id}/andamentos", ['date' => now()->format('Y-m-d'), 'description' => 'Citação positiva'])->assertRedirect();
        $this->assertSame(1, $case->movements()->count());

        $this->get('/buscar?q=cobran')->assertSee('Ação de cobrança');
    }

    public function test_finance_installments_and_settlement(): void
    {
        $this->post('/financeiro', [
            'type' => 'receita', 'description' => 'Honorários', 'amount' => '1.500,00',
            'due_date' => '2026-01-31', 'installments' => 3,
        ])->assertRedirect();

        $entries = FinancialEntry::orderBy('due_date')->get();
        $this->assertCount(3, $entries);
        $this->assertSame('Honorários (1/3)', $entries[0]->description);
        $this->assertSame('2026-02-28', $entries[1]->due_date->format('Y-m-d'));
        $this->assertEquals(1500, (float) $entries[2]->amount);

        $this->patch("/financeiro/{$entries[0]->id}/baixa")->assertRedirect();
        $this->assertNotNull($entries[0]->fresh()->paid_at);
        $this->assertSame('pago', $entries[0]->fresh()->situation);
    }

    public function test_document_template_is_filled_with_client_data(): void
    {
        $contact = Contact::factory()->create(['name' => 'Joana Silva', 'document' => '999.888.777-66']);
        $template = DocumentTemplate::create([
            'name' => 'Procuração', 'category' => 'procuracao',
            'content' => 'Outorgante: {{cliente.nome}}, CPF {{cliente.documento}}. Advogada: {{advogado.nome}} OAB {{advogado.oab}}. RG {{cliente.rg}}',
        ]);

        $this->get("/modelos/{$template->id}/gerar?contact_id={$contact->id}")
            ->assertOk()
            ->assertSee('Outorgante: Joana Silva, CPF 999.888.777-66. Advogada: Dra. Teste OAB SP 1. RG ________________', false);
    }
}
