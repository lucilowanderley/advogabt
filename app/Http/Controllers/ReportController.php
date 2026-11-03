<?php

namespace App\Http\Controllers;

use App\Support\Reports;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $this->tab($request);
        $reports = Reports::fromRequest($request);

        $data = match ($tab) {
            'financeiro' => ['finance' => $reports->finance()],
            'processos' => ['cases' => $reports->cases()],
            'clientes' => ['clients' => $reports->clients()],
            'equipe' => ['team' => $reports->team()],
            default => [
                'finance' => $reports->finance(),
                'cases' => $reports->cases(),
                'clients' => $reports->clients(),
                'team' => $reports->team(),
            ],
        };

        return view('reports.'.$tab, array_merge($data, compact('tab', 'reports')));
    }

    public function export(Request $request): StreamedResponse
    {
        $tab = $this->tab($request);
        $reports = Reports::fromRequest($request);
        $sections = $reports->exportSections($tab);
        $filename = 'relatorio-'.$tab.'-'.$reports->from->format('Ymd').'-'.$reports->to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($sections, $reports, $tab) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
            fputcsv($out, ['ADVOGABT · Relatório: '.Reports::TABS[$tab]['label']], ';');
            fputcsv($out, ['Período: '.$reports->label()], ';');
            foreach ($sections as [$title, $rows]) {
                fputcsv($out, [], ';');
                fputcsv($out, [$title], ';');
                foreach ($rows as $row) {
                    fputcsv($out, array_map([self::class, 'safeCell'], (array) $row), ';');
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Evita que um nome cadastrado como "=HYPERLINK(...)" vire fórmula ao abrir o CSV no Excel
     * (números negativos como "-1.234,56" continuam como estão).
     */
    public static function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && (in_array($value[0], ['=', '+', '@', "\t", "\r"], true) || ($value[0] === '-' && strlen($value) > 1 && ! is_numeric(str_replace(['.', ',', ' '], '', substr($value, 1)))))) {
            return "'".$value;
        }

        return $value;
    }

    private function tab(Request $request): string
    {
        $tab = (string) $request->query('aba', 'geral');

        return array_key_exists($tab, Reports::TABS) ? $tab : 'geral';
    }
}
