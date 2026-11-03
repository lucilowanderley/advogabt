<?php

namespace App\Support\Billing;

/**
 * Leitura do arquivo de retorno CNAB 240 (padrão FEBRABAN), usado por todos os bancos.
 * Cada título vem em um par de linhas: segmento T (identificação) e segmento U (valores e datas).
 */
class Cnab240
{
    /** Códigos de movimento que significam pagamento (liquidação). */
    public const PAID = ['06', '17'];

    public const BANKS = [
        '001' => 'Banco do Brasil', '033' => 'Santander', '077' => 'Banco Inter', '104' => 'Caixa', '237' => 'Bradesco',
        '341' => 'Itaú', '748' => 'Sicredi', '756' => 'Sicoob',
    ];

    /**
     * @return list<array{bank: string, movement: string, our_number: string, document: string, due: ?string, amount: float, paid_amount: float, paid_at: ?string, credited_at: ?string}>
     */
    public static function parse(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', rtrim($content));
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if (! $lines) {
            throw new BillingException('O arquivo está vazio.');
        }
        $length = strlen(rtrim($lines[0], "\r\n"));
        if ($length >= 395 && $length <= 402) {
            throw new BillingException('Este é um arquivo CNAB 400. Peça ao banco o retorno no padrão CNAB 240 (o mais comum hoje) ou use a consulta automática da API.');
        }
        if ($length < 240) {
            throw new BillingException('Não parece um arquivo de retorno CNAB 240 (as linhas têm '.$length.' caracteres).');
        }
        if (substr($lines[0], 142, 1) !== '2' && substr($lines[0], 7, 1) === '0') {
            // posição 143 do header: 1 = remessa, 2 = retorno (alguns bancos deixam em branco)
            if (substr($lines[0], 142, 1) === '1') {
                throw new BillingException('Este é um arquivo de remessa, não de retorno.');
            }
        }

        $items = [];
        $current = null;
        foreach ($lines as $line) {
            $line = str_pad($line, 240);
            if ($line[7] !== '3') {
                continue;
            }
            $segment = strtoupper($line[13]);
            if ($segment === 'T') {
                $current = [
                    'bank' => substr($line, 0, 3),
                    'movement' => substr($line, 15, 2),
                    'our_number' => trim(substr($line, 37, 20)),
                    'document' => trim(substr($line, 58, 15)),
                    'due' => self::date(substr($line, 73, 8)),
                    'amount' => self::money(substr($line, 81, 15)),
                    'paid_amount' => 0.0,
                    'paid_at' => null,
                    'credited_at' => null,
                ];
            } elseif ($segment === 'U' && $current) {
                $current['paid_amount'] = self::money(substr($line, 77, 15));
                $current['paid_at'] = self::date(substr($line, 137, 8));
                $current['credited_at'] = self::date(substr($line, 145, 8));
                $items[] = $current;
                $current = null;
            }
        }

        return $items;
    }

    private static function money(string $value): float
    {
        return ((int) preg_replace('/\D+/', '', $value)) / 100;
    }

    private static function date(string $value): ?string
    {
        if (! preg_match('/^\d{8}$/', $value) || (int) $value === 0) {
            return null;
        }

        return substr($value, 4, 4).'-'.substr($value, 2, 2).'-'.substr($value, 0, 2);
    }
}
