<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $myTasks = Task::forUser($user->id);

        $stats = [
            'done_month' => (clone $myTasks)->where('status', 'concluida')->where('completed_at', '>=', $monthStart)->count(),
            'pending' => (clone $myTasks)->open()->count(),
            'overdue' => (clone $myTasks)->open()->where('due_at', '<', today())->count(),
            'points' => (int) (clone $myTasks)->where('status', 'concluida')->where('completed_at', '>=', $monthStart)->sum('points'),
            'active_cases' => LegalCase::where('status', 'ativo')->count(),
            'clients' => Contact::where('category', 'cliente')->count(),
            'leads' => Contact::whereIn('crm_stage', ['lead', 'contato', 'proposta'])->count(),
        ];

        $finance = [
            'received' => (float) FinancialEntry::where('type', 'receita')->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount'),
            'to_receive' => (float) FinancialEntry::where('type', 'receita')->unpaid()->whereBetween('due_date', [$monthStart, $monthEnd])->sum('amount'),
            'paid' => (float) FinancialEntry::where('type', 'despesa')->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount'),
            'to_pay' => (float) FinancialEntry::where('type', 'despesa')->unpaid()->whereBetween('due_date', [$monthStart, $monthEnd])->sum('amount'),
            'overdue' => (float) FinancialEntry::where('type', 'receita')->unpaid()->where('due_date', '<', today())->sum('amount'),
        ];

        $todayEvents = Event::with('legalCase')
            ->whereBetween('starts_at', [today(), today()->endOfDay()])
            ->orderBy('starts_at')->get();

        $upcomingEvents = Event::with('legalCase')
            ->whereBetween('starts_at', [today()->addDay(), today()->addDays(7)->endOfDay()])
            ->orderBy('starts_at')->limit(6)->get();

        $deadlines = Task::with(['legalCase', 'users'])->open()
            ->where('is_deadline', true)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', today()->addDays(10)->endOfDay())
            ->orderBy('due_at')->limit(8)->get();

        $myOpenTasks = Task::with(['legalCase', 'contact'])->forUser($user->id)->open()
            ->orderByRaw('due_at is null')->orderBy('due_at')->limit(8)->get();

        // Pontuação da equipe no mês (sistema de pontos por tarefa concluída)
        $ranking = User::where('active', true)
            ->withSum(['tasks as points_month' => fn ($q) => $q->where('status', 'concluida')->where('completed_at', '>=', $monthStart)], 'points')
            ->get()
            ->sortByDesc('points_month')
            ->take(5);

        // Receita recebida nos últimos 6 meses (gráfico de barras simples)
        $chart = collect(range(5, 0))->map(function (int $i) {
            $start = now()->startOfMonth()->subMonths($i);

            return [
                'label' => $start->translatedFormat('M'),
                'receita' => (float) FinancialEntry::where('type', 'receita')->whereBetween('paid_at', [$start, $start->copy()->endOfMonth()])->sum('amount'),
                'despesa' => (float) FinancialEntry::where('type', 'despesa')->whereBetween('paid_at', [$start, $start->copy()->endOfMonth()])->sum('amount'),
            ];
        });

        return view('dashboard', compact('stats', 'finance', 'todayEvents', 'upcomingEvents', 'deadlines', 'myOpenTasks', 'ranking', 'chart'));
    }
}
