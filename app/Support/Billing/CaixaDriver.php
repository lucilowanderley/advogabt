<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * Caixa, Webservice de Cobrança Bancária (SIGCB, SOAP). Não há OAuth: a Caixa libera o acesso
 * para o IP do servidor e cada mensagem leva um hash (SHA-256) de beneficiário, nosso número,
 * vencimento, valor e CNPJ. O nosso número (17) começa com "14" (registrada, emissão pelo
 * beneficiário). Registra só boleto (sem Pix); a baixa também pode vir pelo retorno CNAB 240.
 */
class CaixaDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://des.barramento.caixa.gov.br/sibar',
        'producao' => 'https://barramento.caixa.gov.br/sibar',
    ];

    public function test(BillingProvider $p): string
    {
        // Consulta um título que não existe: qualquer resposta do SIGCB prova que o IP está liberado.
        $this->soap($p, 'CONSULTA_BOLETO', self::ourNumber(0), null, null, '<CONSULTA_BOLETO><CODIGO_BENEFICIARIO>'.self::beneficiary($p).'</CODIGO_BENEFICIARIO><NOSSO_NUMERO>'.self::ourNumber(0).'</NOSSO_NUMERO></CONSULTA_BOLETO>', false);

        return 'Webservice da Caixa respondeu ('.($p->isSandbox() ? 'homologação' : 'produção').'), beneficiário '.self::beneficiary($p).'.';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $payer = self::payer($payer, 'Caixa');
        $ourNumber = self::ourNumber($c->id);
        $due = $c->due_date->format('Y-m-d');
        $next = $c->due_date->copy()->addDay()->format('Y-m-d');
        $doc = $payer['pj']
            ? '<CNPJ>'.$payer['doc'].'</CNPJ><RAZAO_SOCIAL>'.self::xml(Str::limit(Str::ascii($payer['name']), 40, '')).'</RAZAO_SOCIAL>'
            : '<CPF>'.$payer['doc'].'</CPF><NOME>'.self::xml(Str::limit(Str::ascii($payer['name']), 40, '')).'</NOME>';
        $interest = (float) $p->interest_percent > 0
            ? '<JUROS_MORA><TIPO>TAXA_MENSAL</TIPO><DATA>'.$next.'</DATA><PERCENTUAL>'.number_format((float) $p->interest_percent, 2, '.', '').'</PERCENTUAL></JUROS_MORA>'
            : '<JUROS_MORA><TIPO>ISENTO</TIPO><VALOR>0</VALOR></JUROS_MORA>';
        $fine = (float) $p->fine_percent > 0
            ? '<MULTA><DATA>'.$next.'</DATA><PERCENTUAL>'.number_format((float) $p->fine_percent, 2, '.', '').'</PERCENTUAL></MULTA>'
            : '';
        $data = '<INCLUI_BOLETO><CODIGO_BENEFICIARIO>'.self::beneficiary($p).'</CODIGO_BENEFICIARIO><TITULO>'
            .'<NOSSO_NUMERO>'.$ourNumber.'</NOSSO_NUMERO>'
            .'<NUMERO_DOCUMENTO>'.self::xml(substr(self::seuNumero($c), 0, 11)).'</NUMERO_DOCUMENTO>'
            .'<DATA_VENCIMENTO>'.$due.'</DATA_VENCIMENTO>'
            .'<VALOR>'.number_format((float) $c->amount, 2, '.', '').'</VALOR>'
            .'<TIPO_ESPECIE>99</TIPO_ESPECIE><FLAG_ACEITE>N</FLAG_ACEITE>'
            .'<DATA_EMISSAO>'.today()->format('Y-m-d').'</DATA_EMISSAO>'
            .$interest
            .'<VALOR_ABATIMENTO>0</VALOR_ABATIMENTO>'
            .'<POS_VENCIMENTO><ACAO>DEVOLVER</ACAO><NUMERO_DIAS>30</NUMERO_DIAS></POS_VENCIMENTO>'
            .'<CODIGO_MOEDA>09</CODIGO_MOEDA>'
            .'<PAGADOR>'.$doc.'<ENDERECO>'
            .'<LOGRADOURO>'.self::xml(Str::limit(Str::ascii($payer['address']), 40, '')).'</LOGRADOURO>'
            .'<BAIRRO>'.self::xml(Str::limit(Str::ascii($payer['district']), 15, '')).'</BAIRRO>'
            .'<CIDADE>'.self::xml(Str::limit(Str::ascii($payer['city']), 15, '')).'</CIDADE>'
            .'<UF>'.$payer['uf'].'</UF><CEP>'.$payer['cep'].'</CEP>'
            .'</ENDERECO></PAGADOR>'
            .$fine
            .'<FICHA_COMPENSACAO><MENSAGENS><MENSAGEM>'.self::xml(Str::limit(Str::ascii($c->description), 40, '')).'</MENSAGEM></MENSAGENS></FICHA_COMPENSACAO>'
            .'</TITULO></INCLUI_BOLETO>';

        $xml = $this->soap($p, 'INCLUI_BOLETO', $ourNumber, $c->due_date->format('dmY'), (float) $c->amount, $data);
        $c->forceFill([
            'external_id' => $ourNumber,
            'bank_number' => $ourNumber,
            'digitable_line' => self::tag($xml, 'LINHA_DIGITAVEL'),
            'barcode' => self::tag($xml, 'CODIGO_BARRAS'),
            'slip_url' => self::tag($xml, 'URL'),
            'remote_status' => 'REGISTRADO',
        ])->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $xml = $this->soap($p, 'CONSULTA_BOLETO', $c->external_id, null, null,
            '<CONSULTA_BOLETO><CODIGO_BENEFICIARIO>'.self::beneficiary($p).'</CODIGO_BENEFICIARIO><NOSSO_NUMERO>'.$c->external_id.'</NOSSO_NUMERO></CONSULTA_BOLETO>');
        $text = Str::upper(Str::ascii(implode(' ', array_filter([self::tag($xml, 'SITUACAO'), self::tag($xml, 'MSG_RETORNO'), self::tag($xml, 'MENSAGEM')]))));
        $status = match (true) {
            str_contains($text, 'LIQUIDAD') || str_contains($text, 'PAGO') => 'pago',
            str_contains($text, 'BAIXAD') => 'cancelado',
            default => self::openStatus($c),
        };

        return new RemoteCharge($status, Str::limit($text, 40, ''), null, null, null, array_filter(['slip_url' => self::tag($xml, 'URL')]));
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->soap($p, 'BAIXA_BOLETO', $c->external_id, null, null,
            '<BAIXA_BOLETO><CODIGO_BENEFICIARIO>'.self::beneficiary($p).'</CODIGO_BENEFICIARIO><NOSSO_NUMERO>'.$c->external_id.'</NOSSO_NUMERO></BAIXA_BOLETO>');
    }

    public static function ourNumber(int $id): string
    {
        return '14'.str_pad((string) $id, 15, '0', STR_PAD_LEFT);
    }

    /** Hash de autenticação do SIGCB: beneficiário (7) + nosso número (17) + vencimento (8) + valor (15) + CNPJ (14). */
    public static function hash(BillingProvider $p, string $ourNumber, ?string $due, ?float $amount): string
    {
        $raw = self::beneficiary($p)
            .str_pad($ourNumber, 17, '0', STR_PAD_LEFT)
            .($due ?? '00000000')
            .str_pad((string) (int) round(($amount ?? 0) * 100), 15, '0', STR_PAD_LEFT)
            .str_pad(self::digits($p->credential('cnpj')), 14, '0', STR_PAD_LEFT);

        return base64_encode(hash('sha256', $raw, true));
    }

    private function soap(BillingProvider $p, string $operation, string $ourNumber, ?string $due, ?float $amount, string $data, bool $strict = true): string
    {
        $consult = $operation === 'CONSULTA_BOLETO';
        $service = $consult ? 'ConsultaCobrancaBancaria/Boleto' : 'ManutencaoCobrancaBancaria/Boleto/Externo';
        $ns = $consult ? 'http://caixa.gov.br/sibar/consulta_cobranca_bancaria/boleto' : 'http://caixa.gov.br/sibar/manutencao_cobranca_bancaria/boleto/externo';
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ext="'.$ns.'" xmlns:sib="http://caixa.gov.br/sibar">'
            .'<soapenv:Body><ext:SERVICO_ENTRADA><sib:HEADER>'
            .'<VERSAO>'.($consult ? '1.0' : '3.0').'</VERSAO>'
            .'<AUTENTICACAO>'.self::hash($p, $ourNumber, $due, $amount).'</AUTENTICACAO>'
            .'<USUARIO_SERVICO>'.($consult ? 'SGCBS01D' : 'SGCBS02P').'</USUARIO_SERVICO>'
            .'<OPERACAO>'.$operation.'</OPERACAO><SISTEMA_ORIGEM>SIGCB</SISTEMA_ORIGEM>'
            .'<DATA_HORA>'.now()->format('YmdHis').'</DATA_HORA>'
            .'</sib:HEADER><DADOS>'.$data.'</DADOS></ext:SERVICO_ENTRADA></soapenv:Body></soapenv:Envelope>';

        $host = self::HOSTS[$p->environment] ?? self::HOSTS['sandbox'];
        $r = $this->call($p, fn () => $this->http()->withHeaders(['SOAPAction' => $operation, 'Accept' => 'text/xml'])
            ->withBody($envelope, 'text/xml; charset=utf-8')->post($host.'/'.$service));
        $xml = $r->body();
        if (! $r->successful() && ! str_contains($xml, 'COD_RETORNO')) {
            throw new BillingException('A Caixa respondeu HTTP '.$r->status().'. Confirme com a agência se o webservice de cobrança está liberado para o IP deste servidor.');
        }
        $code = self::tag($xml, 'COD_RETORNO');
        if ($strict && $code !== null && $code !== '' && (int) $code !== 0) {
            throw new BillingException('A Caixa recusou ('.$code.'): '.(self::tag($xml, 'MSG_RETORNO') ?? self::tag($xml, 'MENSAGEM') ?? 'sem mensagem').'.');
        }

        return $xml;
    }

    private static function tag(string $xml, string $tag): ?string
    {
        // Última ocorrência: no SIGCB o retorno de negócio vem depois do de controle.
        if (! preg_match_all('#<(?:[\w-]+:)?'.$tag.'>(.*?)</(?:[\w-]+:)?'.$tag.'>#s', $xml, $m)) {
            return null;
        }
        $value = trim(html_entity_decode(end($m[1]), ENT_QUOTES | ENT_XML1));

        return $value === '' ? null : $value;
    }

    private static function beneficiary(BillingProvider $p): string
    {
        return str_pad(substr(self::digits($p->credential('beneficiario')), -7), 7, '0', STR_PAD_LEFT);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
