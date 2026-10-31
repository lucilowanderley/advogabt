<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Format
{
    public static function money(int|float|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    /** Converte "1.234,56" ou "1234.56" em float. */
    public static function parseMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $value = preg_replace('/[^\d,.-]/', '', (string) $value);
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    public static function date(?CarbonInterface $date, string $format = 'd/m/Y'): string
    {
        return $date ? $date->format($format) : '—';
    }

    /** "Hoje", "Amanhã", "Ontem" ou a data por extenso curta. */
    public static function relativeDay(?CarbonInterface $date, bool $withTime = false): string
    {
        if (! $date) {
            return 'Sem prazo';
        }
        $time = $withTime && $date->format('H:i') !== '00:00' ? ' '.$date->format('H:i') : '';
        if ($date->isToday()) {
            return 'Hoje'.$time;
        }
        if ($date->isTomorrow()) {
            return 'Amanhã'.$time;
        }
        if ($date->isYesterday()) {
            return 'Ontem'.$time;
        }

        return $date->translatedFormat('j M Y').$time;
    }

    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
