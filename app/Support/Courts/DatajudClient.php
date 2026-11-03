<?php

namespace App\Support\Courts;

/**
 * API Pública do Datajud (CNJ): metadados e movimentos dos processos de todos os tribunais
 * (menos o STF). Gratuita; autentica com uma chave pública divulgada pelo CNJ.
 * https://datajud-wiki.cnj.jus.br/api-publica/
 */
class DatajudClient
{
    public const BASE = 'https://api-publica.datajud.cnj.jus.br';

    public const KEY_HELP = 'https://datajud-wiki.cnj.jus.br/api-publica/acesso';

    /**
     * Registros do processo na base do tribunal (um por grau/sistema).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $number, string $apiKey): array
    {
        $digits = Cnj::digits($number);
        $alias = Cnj::alias($digits);
        if (! $alias) {
            throw new CourtException('O tribunal deste número não está no Datajud (o STF, por exemplo, não está). Confira o número do processo.');
        }

        $r = CourtHttp::send('Datajud', fn () => CourtHttp::request(30)
            ->withHeaders(['Authorization' => 'APIKey '.$apiKey])
            ->asJson()
            ->post(self::BASE.'/api_publica_'.$alias.'/_search', [
                'size' => 10,
                'query' => ['match' => ['numeroProcesso' => $digits]],
            ]));

        if (! $r->successful()) {
            $hint = match (true) {
                in_array($r->status(), [401, 403], true) => ' A chave da API foi recusada: o CNJ pode ter trocado a chave pública. Copie a chave atual em '.self::KEY_HELP.' e cole em Integrações › Tribunais.',
                $r->status() === 429 => ' Limite de consultas atingido; o sistema tenta de novo mais tarde.',
                $r->status() >= 500 => ' O Datajud está instável; o sistema tenta de novo mais tarde.',
                default => '',
            };
            throw new CourtException("O Datajud respondeu HTTP {$r->status()}: ".CourtHttp::detail($r).'.'.$hint);
        }

        return array_values(array_filter(array_map(fn ($hit) => $hit['_source'] ?? null, (array) $r->json('hits.hits', []))));
    }
}
