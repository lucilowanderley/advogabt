<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Support\Billing\Billing;
use App\Support\Billing\BillingException;
use App\Support\Billing\CaixaDriver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bancos com API própria (BB, Itaú, Bradesco, Santander, Sicoob, Sicredi, Caixa) e o retorno CNAB 240. */
class BankBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FinancialEntry $entry;

    private string $cert = "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n";

    private string $key = "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutDefer();
        Cache::flush();
        $this->admin = User::where('role', 'admin')->first();
        $client = Contact::create([
            'name' => 'José Pereira', 'category' => 'cliente', 'email' => 'jose@exemplo.com', 'phone' => '(81) 99999-1111',
            'document' => '529.982.247-25', 'address' => 'Av. Boa Viagem, 100 - Boa Viagem', 'city' => 'Recife', 'state' => 'PE', 'zip_code' => '51011-000',
        ]);
        $this->entry = FinancialEntry::create([
            'type' => 'receita', 'description' => 'Honorários de êxito', 'amount' => 2500.5,
            'due_date' => today()->addDays(5), 'contact_id' => $client->id,
        ]);
    }

    private function provider(string $driver, array $columns, array $settings = []): BillingProvider
    {
        $p = new BillingProvider(['driver' => $driver, 'environment' => 'sandbox', 'active' => true, 'fine_percent' => 2, 'interest_percent' => 1]);
        foreach ($columns as $k => $v) {
            $p->{$k} = $v;
        }
        $p->settings = $settings ?: null;
        $p->save();
        $this->assertTrue($p->isConfigured(), $driver.' deveria estar configurado');

        return $p;
    }

    /** Emite, confere o pedido enviado, simula o pagamento e confere a baixa no Financeiro. */
    private function issueAndPay(string $driver, string $method = 'boleto_pix'): BillingCharge
    {
        $charge = Billing::issue($this->entry, $driver, $method, today()->addDays(5));
        $this->assertSame('pendente', $charge->status, (string) $charge->last_error);
        $this->assertNotEmpty($charge->fresh()->digitable_line, $driver.' sem linha digitável');

        $GLOBALS['bank_paid'] = true;
        Billing::refresh($charge->fresh());
        $charge->refresh();
        $this->assertSame('pago', $charge->status, $driver.' não reconheceu o pagamento');
        $this->assertNotNull($this->entry->fresh()->paid_at);
        unset($GLOBALS['bank_paid']);

        return $charge;
    }

    private static function paid(): bool
    {
        return ! empty($GLOBALS['bank_paid']);
    }

    public function test_banco_do_brasil(): void
    {
        $this->provider('bb', ['client_id' => 'bb-id', 'client_secret' => 'bb-secret', 'api_key' => 'app-key'], ['convenio' => '3128557', 'carteira' => '17', 'variacao' => '35']);
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'oauth')) {
                return Http::response(['access_token' => 'tok-bb', 'expires_in' => 600]);
            }
            if ($r->method() === 'POST' && str_contains($r->url(), '/boletos?')) {
                return Http::response(['numero' => '00031285570000000001', 'linhaDigitavel' => '00190000090312855700000000117172600000250050', 'codigoBarraNumerico' => '0019', 'qrCode' => ['emv' => '000201pixbb', 'url' => 'https://pix.bb.com.br/x']]);
            }

            return Http::response(['codigoEstadoTituloCobranca' => self::paid() ? 6 : 1, 'dataRecebimentoTitulo' => today()->format('d.m.Y'), 'valorPagoSacado' => 2500.5]);
        });

        $charge = $this->issueAndPay('bb');
        $this->assertSame('00031285570000000001', $charge->external_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gw-dev-app-key=app-key') && $r->method() === 'POST' && str_contains($r->url(), '/boletos')
            && $r['numeroConvenio'] === 3128557 && $r['indicadorPix'] === 'S' && $r['pagador']['numeroInscricao'] === '52998224725'
            && $r['pagador']['bairro'] === 'Boa Viagem' && $r['numeroTituloCliente'] === '0003128557'.str_pad((string) $charge->id, 10, '0', STR_PAD_LEFT));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth') && $r->hasHeader('Authorization', 'Basic '.base64_encode('bb-id:bb-secret')));
    }

    public function test_itau(): void
    {
        $this->provider('itau', ['client_id' => 'itau-id', 'client_secret' => 's', 'certificate' => $this->cert, 'private_key' => $this->key],
            ['agencia' => '1500', 'conta' => '12345-6', 'carteira' => '109', 'pix_key' => 'financeiro@escritorio.com.br']);
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/api/jwt')) {
                return Http::response(['access_token' => 'tok-itau', 'expires_in' => 300]);
            }
            if ($r->method() === 'POST') {
                return Http::response(['data' => ['dado_boleto' => ['dados_individuais_boleto' => [['numero_linha_digitavel' => '34191090080000000000115000123456789000000250050', 'codigo_barras' => '341']]], 'dados_qrcode' => ['emv' => '000201pixitau']]]);
            }

            return Http::response(['data' => [['dado_boleto' => [
                'dados_individuais_boleto' => [['situacao_geral_boleto' => self::paid() ? 'Paga' : 'Em Aberto']],
                'pagamentos_cobranca' => [['data_inclusao_pagamento' => today()->format('Y-m-d'), 'valor_pago_total_cobranca' => '2500.50']],
            ]]]]);
        });

        $charge = $this->issueAndPay('itau');
        $this->assertSame('150000123456'.'109'.str_pad((string) $charge->id, 8, '0', STR_PAD_LEFT), $charge->external_id);
        $this->assertSame('000201pixitau', $charge->pix_payload);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/boletos') && $r->hasHeader('x-itau-apikey', 'itau-id')
            && $r['beneficiario']['id_beneficiario'] === '150000123456' && $r['dado_boleto']['valor_total_titulo'] === '00000000000250050'
            && $r['dado_boleto']['descricao_instrumento_cobranca'] === 'boleto_pix' && $r['dados_qrcode']['chave'] === 'financeiro@escritorio.com.br'
            && $r['dado_boleto']['multa']['percentual_multa'] === '000000200000');
    }

    public function test_santander_creates_workspace_once(): void
    {
        $p = $this->provider('santander', ['client_id' => 'san-id', 'client_secret' => 's', 'certificate' => $this->cert, 'private_key' => $this->key],
            ['convenio' => '1234567', 'pix_key' => '11.222.333/0001-81']);
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/auth/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok-san', 'expires_in' => 900]);
            }
            if (str_ends_with($r->url(), '/workspaces')) {
                return Http::response(['id' => 'ws-999']);
            }
            if (str_contains($r->url(), '/bank_slips')) {
                return Http::response(['bankNumber' => $r['bankNumber'], 'digitableLine' => '03399123456789000000000000000000172600000250050', 'barCode' => '033', 'qrCodePix' => '000201pixsan', 'status' => 'ATIVO']);
            }

            return Http::response(['status' => self::paid() ? 'LIQUIDADO' : 'ATIVO', 'settlements' => [['settlementDate' => today()->format('Y-m-d'), 'settlementValue' => 2500.5]]]);
        });

        $charge = $this->issueAndPay('santander');
        $this->assertSame('ws-999', $p->fresh()->credential('workspace'));
        $this->assertSame('1234567.'.$charge->id, $charge->external_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/workspaces/ws-999/bank_slips') && $r['key']['type'] === 'CNPJ'
            && $r['nominalValue'] === '2500.50' && $r['payer']['zipCode'] === '51011-000' && $r['environment'] === 'TESTE');
        Http::assertSentCount(4); // token, workspace, boleto, consulta
    }

    public function test_sicoob_sandbox_with_fixed_token(): void
    {
        $this->provider('sicoob', ['client_id' => 'sicoob-id', 'api_key' => 'token-sandbox'], ['beneficiario' => '25546454', 'conta' => '1234567']);
        Http::fake(function (HttpRequest $r) {
            if ($r->method() === 'POST') {
                return Http::response(['resultado' => ['nossoNumero' => 2588658, 'linhaDigitavel' => '75691301920100002588658010', 'codigoBarras' => '756', 'qrCode' => '000201pixsicoob']]);
            }

            return Http::response(['resultado' => ['situacaoBoleto' => self::paid() ? 'Liquidado' : 'Em Aberto', 'listaHistorico' => [['descricaoHistorico' => 'Liquidação', 'dataHistorico' => today()->format('Y-m-d')]]]]);
        });

        $charge = $this->issueAndPay('sicoob');
        $this->assertSame('2588658', $charge->external_id);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'auth.sicoob'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer token-sandbox') && $r->hasHeader('client_id', 'sicoob-id')
            && $r['numeroCliente'] === 25546454 && $r['codigoCadastrarPIX'] === 1 && $r['tipoMulta'] === 2);
    }

    public function test_sicredi(): void
    {
        $this->provider('sicredi', ['api_key' => 'x-key', 'client_secret' => 'codigo-acesso'], ['beneficiario' => '12345', 'cooperativa' => '6789', 'posto' => '03']);
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/auth/openapi/token')) {
                return Http::response(['access_token' => 'tok-sicredi', 'expires_in' => 300]);
            }
            if ($r->method() === 'POST') {
                return Http::response(['nossoNumero' => '211001290', 'linhaDigitavel' => '74891121100012900678903123451007172600000250050', 'codigoBarras' => '748', 'qrCode' => '000201pixsicredi', 'txid' => 'tx1']);
            }

            return Http::response(['situacao' => self::paid() ? 'LIQUIDADO' : 'EM CARTEIRA', 'dadosLiquidacao' => ['data' => today()->format('Y-m-d'), 'valor' => 2500.5]]);
        });

        $charge = $this->issueAndPay('sicredi');
        $this->assertSame('211001290', $charge->external_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/sb/auth/openapi/token') && $r['username'] === '123456789' && $r['grant_type'] === 'password'
            && $r->hasHeader('context', 'COBRANCA'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cobranca/boleto/v1/boletos') && $r['tipoCobranca'] === 'HIBRIDO'
            && $r->hasHeader('cooperativa', '6789') && $r->hasHeader('posto', '03') && $r['pagador']['tipoPessoa'] === 'PESSOA_FISICA');
    }

    public function test_bradesco_signs_requests(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $privatePem);
        $public = openssl_pkey_get_details($pair)['key'];
        $this->provider('bradesco', ['client_id' => 'brad-id', 'private_key' => $privatePem],
            ['cnpj' => '11.222.333/0001-81', 'agencia' => '3995', 'conta' => '75557', 'carteira' => '09']);
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/auth/server/v1.1/token')) {
                return Http::response(['access_token' => 'tok-brad', 'expires_in' => 3600]);
            }
            if (str_contains($r->url(), 'gerarBoleto')) {
                return Http::response(['ctitloCobrCdent' => $r['ctitloCobrCdent'], 'linhaDig10' => '23793399509000000000107555700007172600000250050', 'wqrcdPdraoMercd' => '000201pixbrad']);
            }

            return Http::response(['titulo' => ['descStatus' => self::paid() ? 'LIQUIDADO' : 'A VENCER', 'dataPagto' => today()->format('d.m.Y'), 'valorPagto' => 250050]]);
        });

        $charge = $this->issueAndPay('bradesco');
        $this->assertEquals(2500.5, (float) $charge->paid_amount);
        Http::assertSent(function (HttpRequest $r) use ($public) {
            if (! str_contains($r->url(), 'gerarBoleto')) {
                return false;
            }
            $path = parse_url($r->url(), PHP_URL_PATH);
            $data = "POST\n{$path}\n\n{$r->body()}\ntok-brad\n{$r->header('X-Brad-Nonce')[0]}\n{$r->header('X-Brad-Timestamp')[0]}\nSHA256";
            $sig = base64_decode(strtr($r->header('X-Brad-Signature')[0], '-_', '+/'));

            return openssl_verify($data, $sig, $public, OPENSSL_ALGO_SHA256) === 1 && $r['nroCpfCnpjBenef'] === 11222333 && $r['vnmnalTitloCobr'] === 250050;
        });
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'token') && count(explode('.', (string) $r['assertion'])) === 3);
    }

    public function test_caixa_soap(): void
    {
        $p = $this->provider('caixa', [], ['beneficiario' => '1234567', 'cnpj' => '11.222.333/0001-81']);
        Http::fake(function (HttpRequest $r) {
            if ($r->header('SOAPAction')[0] === 'INCLUI_BOLETO') {
                return Http::response('<soap:Envelope><soap:Body><ns:SERVICO_SAIDA><CONTROLE_NEGOCIAL><COD_RETORNO>0</COD_RETORNO></CONTROLE_NEGOCIAL><DADOS><INCLUI_BOLETO><CODIGO_BARRAS>10491</CODIGO_BARRAS><LINHA_DIGITAVEL>10491234567000000001400000000017172600000250050</LINHA_DIGITAVEL><URL>https://boletoonline.caixa.gov.br/x</URL></INCLUI_BOLETO></DADOS></ns:SERVICO_SAIDA></soap:Body></soap:Envelope>');
            }

            return Http::response('<SERVICO_SAIDA><COD_RETORNO>0</COD_RETORNO><DADOS><CONSULTA_BOLETO><TITULO><SITUACAO>'.(self::paid() ? 'LIQUIDADO' : 'EM ABERTO').'</SITUACAO></TITULO></CONSULTA_BOLETO></DADOS></SERVICO_SAIDA>');
        });

        $charge = $this->issueAndPay('caixa', 'boleto');
        $this->assertSame(CaixaDriver::ourNumber($charge->id), $charge->external_id);
        $this->assertSame('https://boletoonline.caixa.gov.br/x', $charge->slip_url);
        $hash = CaixaDriver::hash($p, $charge->external_id, $charge->due_date->format('dmY'), 2500.5);
        Http::assertSent(fn (HttpRequest $r) => $r->header('SOAPAction')[0] === 'INCLUI_BOLETO' && str_contains($r->body(), '<AUTENTICACAO>'.$hash.'</AUTENTICACAO>')
            && str_contains($r->body(), '<CPF>52998224725</CPF>') && str_contains($r->body(), '<VALOR>2500.50</VALOR>'));
        $this->assertSame(base64_encode(hash('sha256', '1234567'.$charge->external_id.$charge->due_date->format('dmY').'000000000250050'.'11222333000181', true)), $hash);

        // A Caixa não gera Pix.
        $this->expectException(BillingException::class);
        $this->entry->update(['paid_at' => null]);
        Billing::issue($this->entry->fresh(), 'caixa', 'boleto_pix', today()->addDays(5));
    }

    public function test_cnab_240_return_marks_paid(): void
    {
        $this->provider('caixa', [], ['beneficiario' => '1234567', 'cnpj' => '11222333000181']);
        $charge = BillingCharge::create(['provider' => 'caixa', 'method' => 'boleto', 'financial_entry_id' => $this->entry->id, 'contact_id' => $this->entry->contact_id,
            'description' => 'Honorários', 'amount' => 2500.5, 'due_date' => today()->addDays(5), 'status' => 'pendente']);
        $charge->forceFill(['external_id' => CaixaDriver::ourNumber($charge->id), 'bank_number' => CaixaDriver::ourNumber($charge->id)])->save();
        $other = BillingCharge::create(['provider' => 'caixa', 'method' => 'boleto', 'description' => 'Outro', 'amount' => 100, 'due_date' => today(), 'status' => 'pendente']);
        $other->forceFill(['bank_number' => '14000000000099999'])->save();

        $line = fn (string $s, array $parts) => self::cnab($s, $parts);
        $date = today()->format('dmY');
        $file = implode("\r\n", [
            str_pad('10400000', 142).'2'.str_repeat(' ', 97),
            // pago, achado pelo "seu número" ADV{id}
            $line('T', [[0, '104'], [7, '3'], [15, '06'], [37, str_pad('', 20)], [58, str_pad('ADV'.$charge->id, 15)], [81, '000000000250050']]),
            $line('U', [[0, '104'], [7, '3'], [15, '06'], [77, '000000000252551'], [137, $date], [145, $date]]),
            // pago, achado pelo nosso número
            $line('T', [[0, '104'], [7, '3'], [15, '06'], [37, str_pad('14000000000099999', 20)], [58, str_pad('X1', 15)], [81, '000000000010000']]),
            $line('U', [[0, '104'], [7, '3'], [15, '06'], [77, '000000000010000'], [137, $date], [145, $date]]),
            // baixa sem pagamento: ignorada
            $line('T', [[0, '104'], [7, '3'], [15, '09'], [58, str_pad('ADV999', 15)]]),
            $line('U', [[0, '104'], [7, '3'], [15, '09']]),
            // pagamento de título desconhecido
            $line('T', [[0, '104'], [7, '3'], [15, '06'], [37, str_pad('77777777', 20)], [58, str_pad('ZZZ', 15)]]),
            $line('U', [[0, '104'], [7, '3'], [15, '06'], [77, '000000000005000'], [137, $date]]),
        ]);

        $this->actingAs($this->admin)->post(route('billing.return'), ['file' => UploadedFile::fake()->createWithContent('retorno.ret', $file)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 pagamento(s), 2 baixado(s)') && str_contains($m, 'ZZZ'));
        $charge->refresh();
        $this->assertSame('pago', $charge->status);
        $this->assertEquals(2525.51, (float) $charge->paid_amount);
        $this->assertSame(today()->format('Y-m-d'), $this->entry->fresh()->paid_at->format('Y-m-d'));
        $this->assertSame('pago', $other->fresh()->status);

        $this->actingAs($this->admin)->post(route('billing.return'), ['file' => UploadedFile::fake()->createWithContent('r.ret', str_repeat('0', 400))])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'CNAB 400'));
    }

    public function test_generic_settings_form_saves_bank_fields(): void
    {
        $this->actingAs($this->admin)->get(route('billing.settings', ['conta' => 'sicredi']))->assertOk()->assertSee('Código de acesso')->assertSee('Cooperativa')->assertSee('Caixa');
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'sicredi'), [
            'environment' => 'sandbox', 'api_key' => 'k', 'client_secret' => 'c', 'beneficiario' => '12345', 'cooperativa' => '0101', 'posto' => '02',
            'fine_percent' => '2', 'interest_percent' => '1', 'active' => 1,
        ])->assertRedirect(route('billing.settings', ['conta' => 'sicredi']));
        $p = BillingProvider::forDriver('sicredi');
        $this->assertTrue($p->isConfigured());
        $this->assertSame('0101', $p->credential('cooperativa'));
        $this->assertSame('c', $p->client_secret);

        // Campo obrigatório faltando.
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'bb'), ['environment' => 'sandbox', 'client_id' => 'x', 'fine_percent' => '2', 'interest_percent' => '1'])
            ->assertSessionHasErrors(['client_secret', 'api_key', 'convenio']);

        // Sicoob: certificado só é exigido em produção.
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'sicoob'), ['environment' => 'producao', 'client_id' => 'x', 'beneficiario' => '1', 'conta' => '2', 'fine_percent' => '2', 'interest_percent' => '1'])
            ->assertSessionHasErrors(['certificate_file', 'private_key_file']);
        $this->actingAs($this->admin)->post(route('billing.providers.save', 'sicoob'), ['environment' => 'sandbox', 'client_id' => 'x', 'beneficiario' => '1', 'conta' => '2', 'fine_percent' => '2', 'interest_percent' => '1'])
            ->assertSessionHasNoErrors();
    }

    private static function cnab(string $segment, array $parts): string
    {
        $line = str_repeat(' ', 240);
        $line[13] = $segment;
        foreach ($parts as [$pos, $value]) {
            $line = substr_replace($line, $value, $pos, strlen($value));
        }

        return $line;
    }
}
