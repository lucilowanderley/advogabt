<?php

namespace App\Support\Courts;

use Carbon\CarbonInterface;

/**
 * Diário de Justiça Eletrônico Nacional (DJEN), pela API pública do Comunica PJe (CNJ):
 * intimações, citações e editais publicados por todos os tribunais, pesquisáveis pela OAB.
 * Sem cadastro nem chave. https://comunica.pje.jus.br
 */
class DjenClient
{
    public const BASE = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao';

    public const PER_PAGE = 100;

    public const MAX_PAGES = 20;

    /**
     * Comunicações disponibilizadas entre as datas para a OAB informada.
     *
     * @return list<array<string, mixed>>
     */
    public function byOab(string $oabNumber, string $uf, CarbonInterface $from, CarbonInterface $to): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = [
                'numeroOab' => $oabNumber,
                'ufOab' => strtoupper($uf),
                'dataDisponibilizacaoInicio' => $from->format('Y-m-d'),
                'dataDisponibilizacaoFim' => $to->format('Y-m-d'),
                'pagina' => $page,
                'itensPorPagina' => self::PER_PAGE,
            ];
            $r = CourtHttp::send('DJEN', fn () => CourtHttp::request(40)->get(self::BASE, $query));

            if (! $r->successful()) {
                $hint = match (true) {
                    $r->status() === 429 => ' Limite de consultas atingido; o sistema tenta de novo mais tarde.',
                    $r->status() === 403 => ' O DJEN recusou a consulta (ele costuma bloquear acessos de fora do Brasil).',
                    $r->status() >= 500 => ' O DJEN está instável; o sistema tenta de novo mais tarde.',
                    default => '',
                };
                throw new CourtException("O DJEN respondeu HTTP {$r->status()}: ".CourtHttp::detail($r).'.'.$hint);
            }

            $batch = (array) ($r->json('items') ?? []);
            array_push($items, ...array_values(array_filter($batch, 'is_array')));
            $total = (int) ($r->json('count') ?? 0);
            if (count($batch) < self::PER_PAGE || ($total > 0 && count($items) >= $total)) {
                break;
            }
        }

        return $items;
    }
}
