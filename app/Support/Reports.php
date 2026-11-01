<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios / BI: consolida os dados do escritório por período.
 *
 * As agregações por mês são feitas em PHP (e não com funções de data do banco)
 * para funcionar igual em MySQL e SQLite.
 */
class Reports
{
    public const TABS = [
        'geral' => ['label' => 'Visão geral', 'icon' => 'chart'],
        'financeiro' => ['label' => 'Financeiro', 'icon' => 'wallet'],
        'processos' => ['label' => 'Processos', 'icon' => 'scale'],
        'clientes' => ['label' => 'Clientes e CRM', 'icon' => 'users'],
        'equipe' => ['label' => 'Equipe e prazos', 'icon' => 'trophy'],
    ];

    public const PERIODS = [
        'mes' => 'Este mês',
        'mes_anterior' => 'Mês anterior',
        'trimestre' => 'Últimos 3 meses',
        'semestre' => 'Últimos 6 meses',
        'ano' => 'Este ano',
        '12m' => 'Últimos 12 meses',
        'ano_anterior' => 'Ano anterior',
        'personalizado' => 'Personalizado',
    ];

    /** Dias sem andamento para considerar um processo "parado". */
    public const STALE_DAYS = 60;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public string $period;

    public function __construct(string $period = 'ano', ?string $from = null, ?string $to = null)
    {
        $this->period = array_key_exists($period, self::PERIODS) ? $period : 'ano';
        $today = CarbonImmutable::today();

        [$start, $end] = match ($this->period) {
            'mes' => [$today->startOfMonth(), $today->endOfMonth()],
            'mes_anterior' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'trimestre' => [$today->subMonthsNoOverflow(2)->startOfMonth(), $today->endOfMonth()],
            'semestre' => [$today->subMonthsNoOverflow(5)->startOfMonth(), $today->endOfMonth()],
            '12m' => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today->endOfMonth()],
            'ano_anterior' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'personalizado' => [self::parseDate($from) ?? $today->startOfYear(), self::parseDate($to) ?? $today],
            default => [$today->startOfYear(), $today->endOfYear()],
        };

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        // Limita a 5 anos para manter os gráficos legíveis.
        if ($start->lessThan($end->subYears(5))) {
            $start = $end->subYears(5);
        }

        $this->from = $start->startOfDay();
        $this->to = $end->endOfDay();
    }

    public static function fromRequest(Request $request): self
    {
        return new self((string) $request->query('periodo', 'ano'), $request->query('de'), $request->query('ate'));
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function label(): string
    {
        return $this->from->format('d/m/Y').' a '.$this->to->format('d/m/Y');
    }

    /** Meses do período como ['2026-01' => 'jan/26', ...]. */
    public function months(): array
    {
        $months = [];
        foreach (CarbonPeriod::create($this->from->startOfMonth(), '1 month', $this->to->startOfMonth()) as $m) {
            $months[$m->format('Y-m')] = $m->translatedFormat('M/y');
        }

        return $months;
    }

    private function between(): array
    {
        return [$this->from->toDateTimeString(), $this->to->toDateTimeString()];
    }

    private function betweenDates(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /** Soma valores por mês (chave Y-m) a partir de linhas [data, valor]. */
    private function perMonth(Collection $rows, string $dateKey, ?string $valueKey = null): array
    {
        $series = array_fill_keys(array_keys($this->months()), 0.0);
        foreach ($rows as $row) {
            $date = $row->{$dateKey};
            if (! $date) {
                continue;
            }
            $key = CarbonImmutable::parse($date)->format('Y-m');
            if (array_key_exists($key, $series)) {
                $series[$key] += $valueKey ? (float) $row->{$valueKey} : 1;
            }
        }

        return $series;
    }

    /** Transforma [chave => total] em linhas com rótulo e percentual, ordenadas. */
    private static function breakdown(iterable $totals, array $labels = [], bool $sort = true): array
    {
        $rows = [];
        foreach ($totals as $key => $value) {
            $rows[] = ['key' => (string) $key, 'label' => $labels[$key] ?? ($key === '' ? 'Não informado' : (string) $key), 'value' => (float) $value];
        }
        if ($sort) {
            usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        }
        $sum = array_sum(array_column($rows, 'value'));
        foreach ($rows as &$r) {
            $r['percent'] = $sum > 0 ? round($r['value'] / $sum * 100, 1) : 0;
        }

        return $rows;
    }

    private static function pct(float|int $part, float|int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    // ------------------------------------------------------------------
    // Financeiro
    // ------------------------------------------------------------------

    public function finance(): array
    {
        $paidInPeriod = FinancialEntry::query()->whereNotNull('paid_at')->whereBetween('paid_at', $this->betweenDates());

        $realized = (clone $paidInPeriod)->get(['type', 'amount', 'paid_at', 'category', 'payment_method', 'contact_id', 'legal_case_id']);
        $in = $realized->where('type', 'receita');
        $out = $realized->where('type', 'despesa');

        $income = (float) $in->sum('amount');
        $expense = (float) $out->sum('amount');

        $billed = FinancialEntry::where('type', 'receita')->whereBetween('due_date', $this->betweenDates());
        $billedTotal = (float) (clone $billed)->sum('amount');
        $billedPaid = (float) (clone $billed)->whereNotNull('paid_at')->sum('amount');

        $openReceivable = FinancialEntry::where('type', 'receita')->unpaid();
        $overdueRows = (clone $openReceivable)->where('due_date', '<', today()->toDateString())->get(['amount', 'due_date']);

        $aging = ['1 a 30 dias' => 0.0, '31 a 60 dias' => 0.0, '61 a 90 dias' => 0.0, 'Mais de 90 dias' => 0.0];
        foreach ($overdueRows as $row) {
            $days = (int) $row->due_date->diffInDays(today());
            $bucket = match (true) {
                $days <= 30 => '1 a 30 dias',
                $days <= 60 => '31 a 60 dias',
                $days <= 90 => '61 a 90 dias',
                default => 'Mais de 90 dias',
            };
            $aging[$bucket] += (float) $row->amount;
        }

        $inMonth = $this->perMonth($in, 'paid_at', 'amount');
        $outMonth = $this->perMonth($out, 'paid_at', 'amount');
        $monthly = [];
        $acc = 0.0;
        foreach ($this->months() as $key => $label) {
            $acc += $inMonth[$key] - $outMonth[$key];
            $monthly[] = ['key' => $key, 'label' => $label, 'receita' => $inMonth[$key], 'despesa' => $outMonth[$key], 'saldo' => $inMonth[$key] - $outMonth[$key], 'acumulado' => $acc];
        }

        $topClients = $in->whereNotNull('contact_id')->groupBy('contact_id')->map->sum('amount')->sortDesc()->take(10);
        $clientNames = Contact::whereIn('id', $topClients->keys())->pluck('name', 'id');
        $topCases = $in->whereNotNull('legal_case_id')->groupBy('legal_case_id')->map->sum('amount')->sortDesc()->take(10);
        $caseNames = LegalCase::whereIn('id', $topCases->keys())->get()->mapWithKeys(fn ($c) => [$c->id => $c->display_name]);

        $feeCategories = ['honorarios_contratuais', 'honorarios_exito', 'honorarios_sucumbencia', 'consultoria'];
        $fees = $in->whereIn('category', $feeCategories);

        return [
            'kpis' => [
                'income' => $income,
                'expense' => $expense,
                'result' => $income - $expense,
                'margin' => self::pct($income - $expense, $income),
                'billed' => $billedTotal,
                'collection_rate' => self::pct($billedPaid, $billedTotal),
                'receivable' => (float) (clone $openReceivable)->sum('amount'),
                'payable' => (float) FinancialEntry::where('type', 'despesa')->unpaid()->sum('amount'),
                'overdue' => (float) $overdueRows->sum('amount'),
                'overdue_count' => $overdueRows->count(),
                'fees' => (float) $fees->sum('amount'),
                'avg_ticket' => $in->count() ? $income / $in->count() : 0,
            ],
            'monthly' => $monthly,
            'income_by_category' => self::breakdown($in->groupBy(fn ($e) => (string) $e->category)->map->sum('amount'), Options::FINANCE_CATEGORIES),
            'expense_by_category' => self::breakdown($out->groupBy(fn ($e) => (string) $e->category)->map->sum('amount'), Options::FINANCE_CATEGORIES),
            'by_method' => self::breakdown($in->groupBy(fn ($e) => (string) $e->payment_method)->map->sum('amount'), Options::PAYMENT_METHODS),
            'aging' => self::breakdown($aging, [], false),
            'top_clients' => $topClients->map(fn ($v, $id) => ['id' => $id, 'label' => $clientNames[$id] ?? '—', 'value' => (float) $v])->values()->all(),
            'top_cases' => $topCases->map(fn ($v, $id) => ['id' => $id, 'label' => $caseNames[$id] ?? '—', 'value' => (float) $v])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Processos
    // ------------------------------------------------------------------

    public function cases(): array
    {
        $all = LegalCase::query()->get(['id', 'title', 'number', 'contact_id', 'area', 'phase', 'status', 'district', 'court', 'claim_value', 'fee_percent', 'distributed_at', 'responsible_id', 'created_at']);
        $active = $all->where('status', 'ativo');

        $opened = $all->filter(function ($c) {
            $date = $c->distributed_at ?? $c->created_at;

            return $date && $date->between($this->from, $this->to);
        })->map(function ($c) {
            $c->opened_on = ($c->distributed_at ?? $c->created_at)->toDateString();

            return $c;
        });

        $users = User::pluck('name', 'id');
        $byResponsible = $active->groupBy(fn ($c) => (string) $c->responsible_id)->map->count();
        $responsibleLabels = $byResponsible->keys()->mapWithKeys(fn ($id) => [$id => $id === '' ? 'Sem responsável' : ($users[$id] ?? '—')])->all();

        $lastMovement = DB::table('case_movements')->select('legal_case_id', DB::raw('MAX(date) as last_date'))
            ->groupBy('legal_case_id')->pluck('last_date', 'legal_case_id');
        $staleLimit = today()->subDays(self::STALE_DAYS);
        $stale = LegalCase::with(['client', 'responsible'])->where('status', 'ativo')->get()
            ->map(function ($c) use ($lastMovement) {
                $last = $lastMovement[$c->id] ?? null;
                $c->last_movement = $last ? CarbonImmutable::parse($last) : null;

                return $c;
            })
            ->filter(fn ($c) => $c->last_movement === null || $c->last_movement->lessThan($staleLimit))
            ->sortBy(fn ($c) => $c->last_movement?->timestamp ?? 0)
            ->values();

        $movementsInPeriod = DB::table('case_movements')->whereBetween('date', $this->betweenDates())->count();

        return [
            'kpis' => [
                'active' => $active->count(),
                'total' => $all->count(),
                'opened' => $opened->count(),
                'claim_value' => (float) $active->sum('claim_value'),
                'expected_fees' => (float) $active->sum(fn ($c) => (float) $c->claim_value * (float) $c->fee_percent / 100),
                'stale' => $stale->count(),
                'movements' => $movementsInPeriod,
            ],
            'opened_monthly' => $this->perMonth($opened, 'opened_on'),
            'by_area' => self::breakdown($active->groupBy('area')->map->count(), Options::CASE_AREAS),
            'by_phase' => self::breakdown(collect(Options::CASE_PHASES)->map(fn ($l, $k) => $active->where('phase', $k)->count())->filter(), Options::CASE_PHASES, false),
            'by_status' => self::breakdown(collect(Options::CASE_STATUS)->map(fn ($l, $k) => $all->where('status', $k)->count())->filter(), Options::CASE_STATUS, false),
            'by_responsible' => self::breakdown($byResponsible, $responsibleLabels),
            'by_district' => array_slice(self::breakdown($active->groupBy(fn ($c) => trim((string) ($c->district ?: $c->court)))->map->count()), 0, 8),
            'stale' => $stale->take(15),
        ];
    }

    // ------------------------------------------------------------------
    // Clientes e CRM
    // ------------------------------------------------------------------

    public function clients(): array
    {
        $contacts = Contact::query()->get(['id', 'name', 'category', 'crm_stage', 'person_type', 'origin', 'city', 'state', 'created_at']);
        $clients = $contacts->where('category', 'cliente');
        $newInPeriod = $clients->filter(fn ($c) => $c->created_at && $c->created_at->between($this->from, $this->to));

        $won = $newInPeriod->where('crm_stage', 'cliente')->count();
        $lost = $newInPeriod->where('crm_stage', 'perdido')->count();

        $funnel = collect(Options::CRM_STAGES)->map(fn ($l, $k) => $clients->where('crm_stage', $k)->count());

        $origins = [];
        foreach ($clients->groupBy(fn ($c) => (string) $c->origin) as $origin => $group) {
            $origins[] = [
                'label' => Options::CONTACT_ORIGINS[$origin] ?? ($origin === '' ? 'Não informada' : $origin),
                'total' => $group->count(),
                'won' => $group->where('crm_stage', 'cliente')->count(),
                'lost' => $group->where('crm_stage', 'perdido')->count(),
                'open' => $group->whereIn('crm_stage', ['lead', 'contato', 'proposta'])->count(),
            ];
        }
        foreach ($origins as &$o) {
            $o['rate'] = self::pct($o['won'], $o['won'] + $o['lost']);
        }
        unset($o);
        usort($origins, fn ($a, $b) => $b['total'] <=> $a['total']);

        $revenue = FinancialEntry::where('type', 'receita')->whereNotNull('paid_at')->whereNotNull('contact_id')
            ->select('contact_id', DB::raw('SUM(amount) as total'))->groupBy('contact_id')->pluck('total', 'contact_id');
        $topClients = Contact::where('category', 'cliente')->withCount(['legalCases', 'legalCases as active_cases_count' => fn ($q) => $q->where('status', 'ativo')])
            ->get()
            ->map(function ($c) use ($revenue) {
                $c->revenue_total = (float) ($revenue[$c->id] ?? 0);

                return $c;
            })
            ->filter(fn ($c) => $c->legal_cases_count > 0 || $c->revenue_total > 0)
            ->sortByDesc(fn ($c) => [$c->revenue_total, $c->legal_cases_count])
            ->take(10)->values();

        return [
            'kpis' => [
                'clients' => $clients->where('crm_stage', 'cliente')->count(),
                'new' => $newInPeriod->count(),
                'new_won' => $won,
                'pipeline' => $clients->whereIn('crm_stage', ['lead', 'contato', 'proposta'])->count(),
                'conversion' => self::pct($won, $won + $lost),
                'contacts' => $contacts->count(),
            ],
            'new_monthly' => $this->perMonth($newInPeriod, 'created_at'),
            'funnel' => self::breakdown($funnel, Options::CRM_STAGES, false),
            'origins' => $origins,
            'by_person_type' => self::breakdown($clients->groupBy('person_type')->map->count(), ['PF' => 'Pessoa física', 'PJ' => 'Pessoa jurídica']),
            'by_city' => array_slice(self::breakdown($clients->groupBy(fn ($c) => trim($c->city ? $c->city.($c->state ? '/'.$c->state : '') : ''))->map->count()), 0, 8),
            'by_category' => self::breakdown($contacts->groupBy('category')->map->count(), Options::CONTACT_CATEGORIES),
            'top_clients' => $topClients,
        ];
    }

    // ------------------------------------------------------------------
    // Equipe, tarefas, prazos e agenda
    // ------------------------------------------------------------------

    private static function onTime(Task $t): bool
    {
        return $t->due_at === null || ($t->completed_at && $t->completed_at->lessThanOrEqualTo($t->due_at->copy()->endOfDay()));
    }

    public function team(): array
    {
        $done = Task::with('users:id')->where('status', 'concluida')->whereBetween('completed_at', $this->between())
            ->get(['id', 'points', 'due_at', 'completed_at', 'is_deadline', 'priority']);
        $open = Task::with('users:id')->open()->get(['id', 'due_at', 'is_deadline', 'priority', 'status']);
        $overdue = $open->filter(fn ($t) => $t->due_at && $t->due_at->lt(today()));

        $withDue = $done->whereNotNull('due_at');
        $onTime = $withDue->filter(fn ($t) => self::onTime($t))->count();

        $deadlinesDone = $done->where('is_deadline', true);
        $deadlinesWithDue = $deadlinesDone->whereNotNull('due_at');
        $deadlinesOnTime = $deadlinesWithDue->filter(fn ($t) => self::onTime($t))->count();

        $people = User::where('active', true)->orderBy('name')->get()->map(function (User $u) use ($done, $open, $overdue) {
            $mine = $done->filter(fn ($t) => $t->users->contains('id', $u->id));
            $mineDue = $mine->whereNotNull('due_at');

            return [
                'user' => $u,
                'done' => $mine->count(),
                'points' => (int) $mine->sum('points'),
                'on_time' => self::pct($mineDue->filter(fn ($t) => self::onTime($t))->count(), $mineDue->count()),
                'open' => $open->filter(fn ($t) => $t->users->contains('id', $u->id))->count(),
                'overdue' => $overdue->filter(fn ($t) => $t->users->contains('id', $u->id))->count(),
            ];
        })->sortByDesc('points')->values();

        $events = Event::whereBetween('starts_at', $this->between())->get(['id', 'type', 'starts_at', 'user_id']);

        return [
            'kpis' => [
                'done' => $done->count(),
                'points' => (int) $done->sum('points'),
                'on_time' => self::pct($onTime, $withDue->count()),
                'open' => $open->count(),
                'overdue' => $overdue->count(),
                'deadlines_done' => $deadlinesDone->count(),
                'deadlines_on_time' => self::pct($deadlinesOnTime, $deadlinesWithDue->count()),
                'deadlines_overdue' => $overdue->where('is_deadline', true)->count(),
                'deadlines_next' => $open->where('is_deadline', true)->filter(fn ($t) => $t->due_at && $t->due_at->between(today(), today()->addDays(30)->endOfDay()))->count(),
                'events' => $events->count(),
                'hearings' => $events->where('type', 'audiencia')->count(),
            ],
            'done_monthly' => $this->perMonth($done, 'completed_at'),
            'people' => $people,
            'open_by_priority' => self::breakdown(collect(Options::TASK_PRIORITIES)->map(fn ($l, $k) => $open->where('priority', $k)->count())->filter(), Options::TASK_PRIORITIES, false),
            'events_by_type' => self::breakdown($events->groupBy('type')->map->count(), Options::EVENT_TYPES),
            'events_monthly' => $this->perMonth($events, 'starts_at'),
        ];
    }

    // ------------------------------------------------------------------
    // Exportação CSV (abre direto no Excel, separador ";")
    // ------------------------------------------------------------------

    /** @return array<int, array{0: string, 1: array<int, array<int, mixed>>}> seções [título, linhas] */
    public function exportSections(string $tab): array
    {
        $money = fn ($v) => number_format((float) $v, 2, ',', '');
        $pct = fn ($v) => $v === null ? '' : number_format((float) $v, 1, ',', '').'%';
        $rows = fn (array $items, string $h1, string $h2 = 'Quantidade', bool $isMoney = false) => array_merge(
            [[$h1, $h2, '%']],
            array_map(fn ($r) => [$r['label'], $isMoney ? $money($r['value']) : (int) $r['value'], $pct($r['percent'])], $items),
        );

        switch ($tab) {
            case 'financeiro':
                $f = $this->finance();

                return [
                    ['Fluxo de caixa mensal (realizado)', array_merge(
                        [['Mês', 'Receitas', 'Despesas', 'Saldo', 'Acumulado']],
                        array_map(fn ($m) => [$m['label'], $money($m['receita']), $money($m['despesa']), $money($m['saldo']), $money($m['acumulado'])], $f['monthly']),
                    )],
                    ['Receitas por categoria', $rows($f['income_by_category'], 'Categoria', 'Valor', true)],
                    ['Despesas por categoria', $rows($f['expense_by_category'], 'Categoria', 'Valor', true)],
                    ['Receitas por forma de pagamento', $rows($f['by_method'], 'Forma', 'Valor', true)],
                    ['Inadimplência por atraso', $rows($f['aging'], 'Faixa', 'Valor', true)],
                    ['Maiores clientes (receita recebida)', array_merge([['Cliente', 'Valor']], array_map(fn ($r) => [$r['label'], $money($r['value'])], $f['top_clients']))],
                ];
            case 'processos':
                $c = $this->cases();

                return [
                    ['Processos ativos por área', $rows($c['by_area'], 'Área')],
                    ['Processos ativos por fase', $rows($c['by_phase'], 'Fase')],
                    ['Processos por situação', $rows($c['by_status'], 'Situação')],
                    ['Processos ativos por responsável', $rows($c['by_responsible'], 'Responsável')],
                    ['Novos processos por mês', array_merge([['Mês', 'Quantidade']], $this->zipMonths($c['opened_monthly']))],
                    ['Processos sem andamento há mais de '.self::STALE_DAYS.' dias', array_merge(
                        [['Processo', 'Cliente', 'Responsável', 'Último andamento']],
                        $c['stale']->map(fn ($p) => [$p->display_name, $p->client?->name ?? '', $p->responsible?->name ?? '', $p->last_movement?->format('d/m/Y') ?? 'Nenhum'])->all(),
                    )],
                ];
            case 'clientes':
                $c = $this->clients();

                return [
                    ['Funil de clientes (CRM)', $rows($c['funnel'], 'Etapa')],
                    ['Conversão por origem', array_merge(
                        [['Origem', 'Total', 'Convertidos', 'Perdidos', 'Em negociação', 'Taxa de conversão']],
                        array_map(fn ($o) => [$o['label'], $o['total'], $o['won'], $o['lost'], $o['open'], $pct($o['rate'])], $c['origins']),
                    )],
                    ['Novos clientes por mês', array_merge([['Mês', 'Quantidade']], $this->zipMonths($c['new_monthly']))],
                    ['Principais clientes', array_merge(
                        [['Cliente', 'Processos', 'Processos ativos', 'Receita recebida']],
                        $c['top_clients']->map(fn ($p) => [$p->name, $p->legal_cases_count, $p->active_cases_count, $money($p->revenue_total)])->all(),
                    )],
                ];
            case 'equipe':
                $t = $this->team();

                return [
                    ['Produtividade da equipe', array_merge(
                        [['Usuário', 'Tarefas concluídas', 'Pontos', 'No prazo', 'Em aberto', 'Atrasadas']],
                        $t['people']->map(fn ($p) => [$p['user']->name, $p['done'], $p['points'], $pct($p['on_time']), $p['open'], $p['overdue']])->all(),
                    )],
                    ['Tarefas concluídas por mês', array_merge([['Mês', 'Quantidade']], $this->zipMonths($t['done_monthly']))],
                    ['Compromissos por tipo', $rows($t['events_by_type'], 'Tipo')],
                ];
            default:
                $f = $this->finance()['kpis'];
                $c = $this->cases()['kpis'];
                $cl = $this->clients()['kpis'];
                $t = $this->team()['kpis'];

                return [['Indicadores do período', [
                    ['Indicador', 'Valor'],
                    ['Receitas recebidas', $money($f['income'])],
                    ['Despesas pagas', $money($f['expense'])],
                    ['Resultado', $money($f['result'])],
                    ['Inadimplência', $money($f['overdue'])],
                    ['Processos ativos', $c['active']],
                    ['Novos processos', $c['opened']],
                    ['Novos clientes', $cl['new']],
                    ['Taxa de conversão', $pct($cl['conversion'])],
                    ['Tarefas concluídas', $t['done']],
                    ['Tarefas no prazo', $pct($t['on_time'])],
                    ['Prazos processuais vencidos', $t['deadlines_overdue']],
                ]]];
        }
    }

    private function zipMonths(array $series): array
    {
        $labels = $this->months();

        return array_map(fn ($k) => [$labels[$k], (int) $series[$k]], array_keys($series));
    }
}
