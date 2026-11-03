<?php

namespace App\Support\Courts;

/**
 * Numeração única dos processos (Resolução CNJ 65/2008): NNNNNNN-DD.AAAA.J.TR.OOOO.
 * O segmento (J) e o tribunal (TR) dizem em qual base do Datajud o processo está.
 */
class Cnj
{
    /** Ordem das UFs no código TR da Justiça Estadual e Eleitoral. */
    private const UF = [
        '01' => 'ac', '02' => 'al', '03' => 'ap', '04' => 'am', '05' => 'ba', '06' => 'ce', '07' => 'df', '08' => 'es', '09' => 'go',
        '10' => 'ma', '11' => 'mt', '12' => 'ms', '13' => 'mg', '14' => 'pa', '15' => 'pb', '16' => 'pr', '17' => 'pe', '18' => 'pi',
        '19' => 'rj', '20' => 'rn', '21' => 'rs', '22' => 'ro', '23' => 'rr', '24' => 'sc', '25' => 'se', '26' => 'sp', '27' => 'to',
    ];

    /** Justiça Militar Estadual: só MG, RS e SP têm tribunal próprio. */
    private const TJM = ['13' => 'tjmmg', '21' => 'tjmrs', '26' => 'tjmsp'];

    public static function digits(?string $number): string
    {
        return preg_replace('/\D/', '', (string) $number);
    }

    /** Número com 20 dígitos e dígito verificador correto (módulo 97). */
    public static function isValid(?string $number): bool
    {
        $d = self::digits($number);
        if (strlen($d) !== 20) {
            return false;
        }
        $base = substr($d, 0, 7).substr($d, 9, 11).'00';

        return 98 - self::mod97($base) === (int) substr($d, 7, 2);
    }

    public static function mask(string $number): string
    {
        $d = self::digits($number);
        if (strlen($d) !== 20) {
            return $number;
        }

        return substr($d, 0, 7).'-'.substr($d, 7, 2).'.'.substr($d, 9, 4).'.'.substr($d, 13, 1).'.'.substr($d, 14, 2).'.'.substr($d, 16, 4);
    }

    /** Nome da base no Datajud (api_publica_<alias>) ou null se o tribunal não estiver no Datajud (ex.: STF). */
    public static function alias(?string $number): ?string
    {
        $d = self::digits($number);
        if (strlen($d) !== 20) {
            return null;
        }
        $j = $d[13];
        $tr = substr($d, 14, 2);

        return match ($j) {
            '3' => 'stj',
            '4' => in_array($tr, ['01', '02', '03', '04', '05', '06'], true) ? 'trf'.(int) $tr : null,
            '5' => $tr === '00' ? 'tst' : ((int) $tr >= 1 && (int) $tr <= 24 ? 'trt'.(int) $tr : null),
            '6' => $tr === '00' ? 'tse' : (isset(self::UF[$tr]) ? 'tre-'.self::UF[$tr] : null),
            '7' => 'stm',
            '8' => isset(self::UF[$tr]) ? ($tr === '07' ? 'tjdft' : 'tj'.self::UF[$tr]) : null,
            '9' => self::TJM[$tr] ?? null,
            default => null,
        };
    }

    /** Sigla do tribunal para exibição (TJSP, TRF1, TRE-SP...). */
    public static function court(?string $number): ?string
    {
        $alias = self::alias($number);

        return $alias ? strtoupper($alias) : null;
    }

    private static function mod97(string $digits): int
    {
        $r = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $r = (int) (($r.$chunk) % 97);
        }

        return $r;
    }
}
