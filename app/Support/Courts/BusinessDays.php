<?php

namespace App\Support\Courts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Contagem de prazos em dias úteis (CPC, art. 219).
 *
 * Publicação no DJEN: considera-se publicada no 1º dia útil seguinte ao da disponibilização
 * (Lei 11.419/2006, art. 4º, § 3º) e o prazo começa no 1º dia útil seguinte ao da
 * publicação (§ 4º). Não contamos sábados, domingos, feriados nacionais, a Sexta-feira
 * Santa, o recesso de 20/12 a 20/01 (CPC, art. 220) e os feriados locais informados pelo
 * escritório. O resultado é uma sugestão: o advogado confere (feriados locais, suspensões).
 */
class BusinessDays
{
    /** @var array<string, true> */
    private array $extra = [];

    /** @var array<string, true> */
    private array $yearly = [];

    public function __construct(?string $holidays = null)
    {
        foreach (preg_split('/[\r\n,;]+/', (string) $holidays) as $line) {
            if (preg_match('/^\s*(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/', $line, $m)) {
                $md = sprintf('%02d-%02d', $m[2], $m[1]);
                if (! empty($m[3])) {
                    $this->extra[$m[3].'-'.$md] = true;
                } else {
                    $this->yearly[$md] = true;
                }
            }
        }
    }

    public function isBusinessDay(CarbonInterface $day): bool
    {
        if ($day->isWeekend()) {
            return false;
        }
        $md = $day->format('m-d');
        if ($md >= '12-20' || $md <= '01-20') {
            return false; // recesso forense (CPC, art. 220)
        }
        if (isset($this->yearly[$md]) || isset($this->extra[$day->format('Y-m-d')])) {
            return false;
        }

        return ! isset(self::nationalHolidays($day->year)[$md]);
    }

    public function nextBusinessDay(CarbonInterface $day): CarbonImmutable
    {
        $d = CarbonImmutable::parse($day->format('Y-m-d'))->addDay();
        while (! $this->isBusinessDay($d)) {
            $d = $d->addDay();
        }

        return $d;
    }

    /** Data da publicação: 1º dia útil depois da disponibilização. */
    public function publicationDate(CarbonInterface $available): CarbonImmutable
    {
        return $this->nextBusinessDay($available);
    }

    /** Último dia do prazo de N dias úteis contado a partir da disponibilização no diário. */
    public function deadline(CarbonInterface $available, int $days): CarbonImmutable
    {
        $d = $this->nextBusinessDay($this->publicationDate($available)); // 1º dia do prazo
        for ($i = 1; $i < max(1, $days); $i++) {
            $d = $this->nextBusinessDay($d);
        }

        return $d;
    }

    /** @return array<string, string> feriados nacionais do ano (m-d => nome) */
    public static function nationalHolidays(int $year): array
    {
        $easter = CarbonImmutable::create($year, 3, 21)->addDays(self::easterDays($year));

        return [
            '01-01' => 'Confraternização Universal',
            $easter->subDays(2)->format('m-d') => 'Sexta-feira Santa',
            '04-21' => 'Tiradentes',
            '05-01' => 'Dia do Trabalho',
            '09-07' => 'Independência',
            '10-12' => 'Nossa Senhora Aparecida',
            '11-02' => 'Finados',
            '11-15' => 'Proclamação da República',
            '11-20' => 'Dia Nacional de Zumbi e da Consciência Negra',
            '12-25' => 'Natal',
        ];
    }

    /** Dias entre 21 de março e o domingo de Páscoa (algoritmo de Meeus), sem depender da extensão calendar. */
    private static function easterDays(int $year): int
    {
        if (function_exists('easter_days')) {
            return easter_days($year);
        }
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return (int) CarbonImmutable::create($year, 3, 21)->diffInDays(CarbonImmutable::create($year, $month, $day));
    }
}
