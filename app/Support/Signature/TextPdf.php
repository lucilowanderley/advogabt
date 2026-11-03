<?php

namespace App\Support\Signature;

/**
 * Gera um PDF simples (A4, Times) a partir do texto de um Modelo, sem bibliotecas externas.
 * Os parágrafos seguem as quebras de linha do texto e são justificados; a primeira linha em
 * maiúsculas vira o título centralizado e linhas curtas em maiúsculas (cláusulas) saem em negrito.
 */
class TextPdf
{
    private const W = 595.28;

    private const H = 841.89;

    private const MARGIN_X = 70.87; // 2,5 cm

    private const MARGIN_TOP = 70.87;

    private const MARGIN_BOTTOM = 70.87;

    private const SIZE = 12.0;

    private const LEADING = 18.0;

    /** Larguras (1/1000 em) dos caracteres 32-255 na codificação WinAnsi. */
    private const ROMAN = [250, 333, 408, 500, 500, 833, 778, 180, 333, 333, 500, 564, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 278, 278, 564, 564, 564, 444, 921, 722, 667, 667, 722, 611, 556, 722, 722, 333, 389, 722, 611, 889, 722, 722, 556, 722, 667, 556, 611, 722, 722, 944, 722, 722, 611, 333, 278, 333, 469, 500, 333, 444, 500, 444, 500, 444, 333, 500, 500, 278, 278, 500, 278, 778, 500, 500, 500, 500, 333, 389, 278, 500, 500, 722, 500, 500, 444, 480, 200, 480, 541, 350, 500, 350, 333, 500, 444, 1000, 500, 500, 333, 1000, 556, 333, 889, 350, 611, 350, 350, 333, 333, 444, 444, 350, 500, 1000, 333, 980, 389, 333, 722, 350, 444, 722, 250, 333, 500, 500, 500, 500, 200, 500, 333, 760, 276, 500, 564, 333, 760, 333, 400, 564, 300, 300, 333, 500, 453, 250, 333, 300, 310, 500, 750, 750, 750, 444, 722, 722, 722, 722, 722, 722, 889, 667, 611, 611, 611, 611, 333, 333, 333, 333, 722, 722, 722, 722, 722, 722, 722, 564, 722, 722, 722, 722, 722, 722, 556, 500, 444, 444, 444, 444, 444, 444, 667, 444, 444, 444, 444, 444, 278, 278, 278, 278, 500, 500, 500, 500, 500, 500, 500, 564, 500, 500, 500, 500, 500, 500, 500, 500];

    private const BOLD = [250, 333, 555, 500, 500, 1000, 833, 278, 333, 333, 500, 570, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 570, 570, 570, 500, 930, 722, 667, 722, 722, 667, 611, 778, 778, 389, 500, 778, 667, 944, 722, 778, 611, 778, 722, 556, 667, 722, 722, 1000, 722, 722, 667, 333, 278, 333, 581, 500, 333, 500, 556, 444, 556, 444, 333, 500, 556, 278, 333, 556, 278, 833, 556, 500, 556, 556, 444, 389, 333, 556, 500, 722, 500, 500, 444, 394, 220, 394, 520, 350, 500, 350, 333, 500, 500, 1000, 500, 500, 333, 1000, 556, 333, 1000, 350, 667, 350, 350, 333, 333, 500, 500, 350, 500, 1000, 333, 1000, 389, 333, 722, 350, 444, 722, 250, 333, 500, 500, 500, 500, 220, 500, 333, 747, 300, 500, 570, 333, 747, 333, 400, 570, 300, 300, 333, 556, 540, 250, 333, 300, 330, 500, 750, 750, 750, 500, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 389, 389, 389, 389, 722, 722, 778, 778, 778, 778, 778, 570, 778, 722, 722, 722, 722, 722, 611, 556, 500, 500, 500, 500, 500, 500, 722, 444, 444, 444, 444, 444, 278, 278, 278, 278, 500, 556, 500, 500, 500, 500, 500, 570, 500, 556, 556, 556, 556, 500, 556, 500];

    public static function fromText(string $text, string $title = '', string $footer = ''): string
    {
        return (new self)->build($text, $title, $footer);
    }

    private function build(string $text, string $title, string $footer): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $width = self::W - 2 * self::MARGIN_X;
        $lines = [];
        $first = true;
        foreach (explode("\n", $text) as $paragraph) {
            $raw = rtrim($paragraph);
            if (trim($raw) === '') {
                $lines[] = null; // linha em branco

                continue;
            }
            $isCaps = mb_strlen(trim($raw)) <= 90 && preg_match('/\p{Lu}/u', $raw) && mb_strtoupper($raw) === $raw && ! str_contains($raw, '__');
            $center = $isCaps && $first;
            $first = false;
            $size = $center ? 14.0 : self::SIZE;
            $font = $isCaps ? 'F2' : 'F1';
            $wrapped = $this->wrap(self::encode($center ? trim($raw) : $raw), $width, $font, $size);
            foreach ($wrapped as $i => $segment) {
                $last = $i === count($wrapped) - 1;
                $w = $this->width($segment, $font, $size);
                $spaces = substr_count(rtrim($segment), ' ');
                $lines[] = [
                    'font' => $font,
                    'size' => $size,
                    'x' => self::MARGIN_X + ($center ? max(0, ($width - $w) / 2) : 0),
                    'text' => $segment,
                    'tw' => ! $last && ! $center && $spaces > 0 ? max(0, ($width - $w) / $spaces) : 0.0,
                    'gap' => $center ? 6.0 : 0.0,
                ];
            }
        }
        while ($lines && end($lines) === null) {
            array_pop($lines);
        }

        // Paginação
        $pages = [[]];
        $y = self::H - self::MARGIN_TOP;
        foreach ($lines as $line) {
            $advance = $line === null ? self::LEADING * 0.7 : self::LEADING + $line['gap'];
            if ($y - $advance < self::MARGIN_BOTTOM && $pages[array_key_last($pages)]) {
                $pages[] = [];
                $y = self::H - self::MARGIN_TOP;
                if ($line === null) {
                    continue;
                }
            }
            if ($line !== null) {
                $line['y'] = $y - self::SIZE;
                $pages[array_key_last($pages)][] = $line;
            }
            $y -= $advance;
        }

        return $this->render($pages, $title, $footer);
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, string $font, float $size): array
    {
        preg_match('/^ */', $text, $m);
        $words = preg_split('/ +/', ltrim($text, ' '));
        $out = [];
        $current = $m[0];
        foreach ($words as $word) {
            $candidate = trim($current) === '' ? $current.$word : $current.' '.$word;
            if (trim($current) !== '' && $this->width($candidate, $font, $size) > $width) {
                $out[] = $current;
                $candidate = $word;
            }
            // palavra maior que a linha inteira: quebra no meio
            while ($this->width($candidate, $font, $size) > $width && strlen($candidate) > 1) {
                $cut = strlen($candidate) - 1;
                while ($cut > 1 && $this->width(substr($candidate, 0, $cut), $font, $size) > $width) {
                    $cut--;
                }
                $out[] = substr($candidate, 0, $cut);
                $candidate = substr($candidate, $cut);
            }
            $current = $candidate;
        }
        $out[] = $current;

        return $out;
    }

    private function width(string $s, string $font, float $size): float
    {
        $table = $font === 'F2' ? self::BOLD : self::ROMAN;
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $sum += $c >= 32 ? $table[$c - 32] : 0;
        }

        return $sum * $size / 1000;
    }

    /** UTF-8 → Windows-1252 (caracteres fora dela viram "?"). */
    private static function encode(string $s): string
    {
        $s = strtr($s, ["\u{2010}" => '-', "\u{2011}" => '-', "\u{2212}" => '-', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ']);
        $out = function_exists('mb_convert_encoding') ? @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : false;

        return is_string($out) ? $out : (string) preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    private static function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '']);
    }

    private function render(array $pages, string $title, string $footer): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $total = count($pages);
        $next = 5;
        foreach ($pages as $n => $lines) {
            $stream = '';
            foreach ($lines as $l) {
                $stream .= sprintf("BT /%s %.1F Tf %.3F Tw %.2F %.2F Td (%s) Tj ET\n", $l['font'], $l['size'], $l['tw'], $l['x'], $l['y'], self::escape($l['text']));
            }
            $foot = self::encode(($footer !== '' ? $footer.' - ' : '').'Página '.($n + 1).' de '.$total);
            $fw = $this->width($foot, 'F1', 8.5);
            $stream .= sprintf("0.4 g BT /F1 8.5 Tf %.2F %.2F Td (%s) Tj ET 0 g\n", (self::W - $fw) / 2, 34.0, self::escape($foot));

            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentId);
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$total.' >>';
        $infoId = $next;
        $objects[$infoId] = '<< /Title ('.self::escape(self::encode($title)).') /Producer ('.self::escape(self::encode((string) config('app.name', 'ADVOGABT'))).') /CreationDate (D:'.now()->format('YmdHis').') >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".($infoId + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $infoId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer'."\n<< /Size ".($infoId + 1)." /Root 1 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
