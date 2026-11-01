<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\User;
use App\Support\Reports;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->first());
    }

    public function test_every_tab_and_period_renders(): void
    {
        foreach (array_keys(Reports::TABS) as $tab) {
            foreach (array_keys(Reports::PERIODS) as $period) {
                $this->get("/relatorios?aba={$tab}&periodo={$period}&de=2026-01-01&ate=2026-12-31")->assertOk();
            }
        }
        $this->get('/relatorios')->assertOk()->assertSee('Relatórios / BI');
        $this->get('/relatorios?aba=invalida&periodo=xyz&de=lixo')->assertOk();
    }

    public function test_csv_export_for_every_tab(): void
    {
        foreach (array_keys(Reports::TABS) as $tab) {
            $response = $this->get("/relatorios/exportar?aba={$tab}&periodo=12m");
            $response->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('ADVOGABT', $response->streamedContent());
        }
    }

    public function test_finance_totals_match_entries_in_period(): void
    {
        FinancialEntry::query()->delete();
        FinancialEntry::create(['type' => 'receita', 'description' => 'A', 'amount' => 1000, 'due_date' => '2026-03-10', 'paid_at' => '2026-03-12', 'category' => 'honorarios_contratuais']);
        FinancialEntry::create(['type' => 'receita', 'description' => 'B', 'amount' => 500, 'due_date' => '2026-03-20']);
        FinancialEntry::create(['type' => 'despesa', 'description' => 'C', 'amount' => 300, 'due_date' => '2026-04-01', 'paid_at' => '2026-04-01']);
        FinancialEntry::create(['type' => 'receita', 'description' => 'Fora', 'amount' => 9999, 'due_date' => '2025-01-01', 'paid_at' => '2025-01-01']);

        $k = (new Reports('personalizado', '2026-01-01', '2026-12-31'))->finance()['kpis'];

        $this->assertSame(1000.0, $k['income']);
        $this->assertSame(300.0, $k['expense']);
        $this->assertSame(700.0, $k['result']);
        $this->assertSame(1500.0, $k['billed']);
        $this->assertEquals(66.7, $k['collection_rate']);
        $this->assertSame(1000.0, $k['fees']);
    }

    public function test_menu_no_longer_marks_reports_as_coming_soon(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString(route('reports.index'), $html);
        $this->assertDoesNotMatchRegularExpression('/Relatórios \/ BI\s*<span class="soon">/', $html);
    }
}
