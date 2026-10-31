<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** URL de retorno enviada pelo formulário (somente do próprio sistema). */
    protected function backTo(Request $request, string $default): string
    {
        $url = (string) $request->input('redirect');

        if ($url !== '' && (str_starts_with($url, url('/')) || (str_starts_with($url, '/') && ! str_starts_with($url, '//')))) {
            return $url;
        }

        return $default;
    }
}
