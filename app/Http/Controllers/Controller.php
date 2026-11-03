<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

abstract class Controller
{
    /** URL de retorno enviada pelo formulário (somente do próprio sistema). */
    protected function backTo(Request $request, string $default): string
    {
        $url = (string) $request->input('redirect');

        $base = rtrim(url('/'), '/');
        $internal = $url === $base || str_starts_with($url, $base.'/') || str_starts_with($url, $base.'?')
            || (str_starts_with($url, '/') && ! str_starts_with($url, '//'));
        // "\" vira "/" nos navegadores ("/\\site.com" levaria para fora do sistema).
        if ($url !== '' && $internal && ! str_contains($url, '\\') && ! preg_match('/[\x00-\x1F]/', $url)) {
            return $url;
        }

        return $default;
    }

    /** Data vinda da URL (?data=...); valor inválido é ignorado em vez de virar erro 500. */
    protected function queryDate(Request $request, string $key = 'data'): ?Carbon
    {
        $value = $request->query($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
