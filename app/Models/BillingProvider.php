<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Conta do escritório em uma plataforma de cobrança (Asaas ou Banco Inter). */
class BillingProvider extends Model
{
    /**
     * Plataformas e bancos. "fields" são as credenciais pedidas na tela: as chaves que são colunas
     * (COLUMNS) vão para a coluna (segredos criptografados); as demais ficam em settings.
     * Tipos: text, secret, cert (.crt) e key (.key). "required" = obrigatório para emitir.
     */
    public const DRIVERS = [
        'asaas' => [
            'label' => 'Asaas',
            'kind' => 'plataforma',
            'pricing' => 'comercial',
            'site' => 'https://www.asaas.com',
            'methods' => ['boleto_pix', 'pix', 'livre'],
            'pdf' => false,
            'note' => 'Conta de pagamentos com boleto, Pix e cartão. Cobra uma tarifa por boleto/Pix recebido, sem mensalidade. Também emite a NFS-e da prefeitura do escritório.',
            'help' => 'No Asaas: Integrações › Chaves de API › Gerar chave. O ambiente de testes (sandbox.asaas.com) é gratuito e tem conta e chave próprias (começam com $aact_hmlg_).',
            'fields' => [
                'api_key' => ['label' => 'Chave de API', 'type' => 'secret', 'required' => true, 'col' => 12],
            ],
        ],
        'inter' => [
            'label' => 'Banco Inter',
            'kind' => 'banco',
            'pricing' => 'gratis',
            'site' => 'https://developers.inter.co',
            'methods' => ['boleto_pix'],
            'pdf' => true,
            'note' => 'Boleto com QR Code Pix (BolePix) emitido pela conta PJ do escritório. A conta digital PJ do Inter tem cota de boletos gratuitos por mês.',
            'help' => 'No Internet Banking PJ do Inter: Soluções para sua empresa › Nova Integração. Marque "Emissão e consulta de boletos", baixe a chave (.key) e o certificado (.crt) e copie o Client ID e o Client Secret.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'client_secret' => ['label' => 'Client Secret', 'type' => 'secret', 'required' => true],
                'certificate' => ['label' => 'Certificado (.crt)', 'type' => 'cert', 'required' => true],
                'private_key' => ['label' => 'Chave (.key)', 'type' => 'key', 'required' => true],
                'account_number' => ['label' => 'Conta corrente (opcional)', 'type' => 'text', 'hint' => 'Só se a integração estiver ligada a mais de uma conta.'],
            ],
        ],
        'bb' => [
            'label' => 'Banco do Brasil',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://developers.bb.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => false,
            'note' => 'API Cobranças v2: boleto registrado com QR Code Pix na carteira de cobrança do escritório. A tarifa é a do convênio contratado com o BB.',
            'help' => 'No Portal Developers BB: crie a aplicação com a API "Cobranças" (escopos boletos-info e boletos-requisicao), copie o Client ID, o Client Secret e a Developer Application Key. O convênio, a carteira e a variação estão no contrato de cobrança (peça ao gerente). Em produção o BB pode exigir o certificado da aplicação.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'client_secret' => ['label' => 'Client Secret', 'type' => 'secret', 'required' => true],
                'api_key' => ['label' => 'Developer Application Key', 'type' => 'secret', 'required' => true],
                'convenio' => ['label' => 'Número do convênio', 'type' => 'text', 'required' => true, 'col' => 4],
                'carteira' => ['label' => 'Carteira', 'type' => 'text', 'required' => true, 'col' => 4, 'default' => '17'],
                'variacao' => ['label' => 'Variação da carteira', 'type' => 'text', 'required' => true, 'col' => 4, 'default' => '35'],
                'certificate' => ['label' => 'Certificado (.crt, se exigido)', 'type' => 'cert'],
                'private_key' => ['label' => 'Chave (.key, se exigida)', 'type' => 'key'],
            ],
        ],
        'itau' => [
            'label' => 'Itaú',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://devportal.itau.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => false,
            'note' => 'API de Boletos (Cash Management v2): boleto registrado, com QR Code Pix quando a chave Pix é informada. A tarifa é a do contrato de cobrança.',
            'help' => 'No portal Itaú for Developers (ou com o gerente PJ): peça acesso à API de Boletos, gere o certificado dinâmico (.crt e .key) e copie o Client ID e o Client Secret. Agência, conta com dígito e carteira (geralmente 109) estão no contrato.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'client_secret' => ['label' => 'Client Secret', 'type' => 'secret', 'required' => true],
                'certificate' => ['label' => 'Certificado (.crt)', 'type' => 'cert', 'required' => true],
                'private_key' => ['label' => 'Chave (.key)', 'type' => 'key', 'required' => true],
                'agencia' => ['label' => 'Agência', 'type' => 'text', 'required' => true, 'col' => 3],
                'conta' => ['label' => 'Conta com dígito', 'type' => 'text', 'required' => true, 'col' => 3],
                'carteira' => ['label' => 'Carteira', 'type' => 'text', 'required' => true, 'col' => 2, 'default' => '109'],
                'pix_key' => ['label' => 'Chave Pix (para o QR Code)', 'type' => 'text', 'col' => 4],
            ],
        ],
        'bradesco' => [
            'label' => 'Bradesco',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://developers.bradesco.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => false,
            'note' => 'API de Cobrança (boleto híbrido com Pix). As chamadas são assinadas com a chave privada da aplicação. A tarifa é a do contrato de cobrança.',
            'help' => 'No Bradesco Developers (com o gerente PJ): cadastre a aplicação de Cobrança, envie a chave pública do seu certificado e copie o Client ID. Envie aqui a chave privada (.key) correspondente. Agência, conta e carteira (geralmente 09) estão no contrato.',
            'fields' => [
                'client_id' => ['label' => 'Client ID (API Key)', 'type' => 'text', 'required' => true],
                'private_key' => ['label' => 'Chave privada (.key)', 'type' => 'key', 'required' => true],
                'certificate' => ['label' => 'Certificado (.crt, se exigido)', 'type' => 'cert'],
                'cnpj' => ['label' => 'CNPJ do escritório', 'type' => 'text', 'required' => true, 'col' => 6],
                'agencia' => ['label' => 'Agência', 'type' => 'text', 'required' => true, 'col' => 2],
                'conta' => ['label' => 'Conta', 'type' => 'text', 'required' => true, 'col' => 2],
                'carteira' => ['label' => 'Carteira', 'type' => 'text', 'required' => true, 'col' => 2, 'default' => '09'],
            ],
        ],
        'santander' => [
            'label' => 'Santander',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://developer.santander.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => false,
            'note' => 'API de Cobrança (gestão de boletos v2): boleto registrado com QR Code Pix. O sistema cria sozinho o "workspace" de cobrança na primeira emissão. A tarifa é a do convênio.',
            'help' => 'No Santander Developers: crie a aplicação com a API de Cobrança, envie o certificado e copie o Client ID e o Client Secret. O código do convênio de cobrança está no contrato; a chave Pix é necessária para o QR Code.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'client_secret' => ['label' => 'Client Secret', 'type' => 'secret', 'required' => true],
                'certificate' => ['label' => 'Certificado (.crt)', 'type' => 'cert', 'required' => true],
                'private_key' => ['label' => 'Chave (.key)', 'type' => 'key', 'required' => true],
                'convenio' => ['label' => 'Código do convênio', 'type' => 'text', 'required' => true, 'col' => 4],
                'pix_key' => ['label' => 'Chave Pix', 'type' => 'text', 'col' => 4],
                'workspace' => ['label' => 'Workspace (opcional)', 'type' => 'text', 'col' => 4, 'hint' => 'Deixe vazio: o sistema cria.'],
            ],
        ],
        'sicoob' => [
            'label' => 'Sicoob',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://developers.sicoob.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => true,
            'note' => 'API Cobrança Bancária v3: boleto registrado com QR Code Pix na conta do escritório na cooperativa.',
            'help' => 'No Sicoob Developers: crie o aplicativo com a API "Cobrança Bancária", envie o certificado e copie o Client ID. O número do cliente (beneficiário) e a conta corrente estão no Sicoobnet. Para o sandbox, informe também o token de teste do portal.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'certificate' => ['label' => 'Certificado (.crt)', 'type' => 'cert', 'required' => 'producao'],
                'private_key' => ['label' => 'Chave (.key)', 'type' => 'key', 'required' => 'producao'],
                'beneficiario' => ['label' => 'Número do cliente', 'type' => 'text', 'required' => true, 'col' => 4],
                'conta' => ['label' => 'Conta corrente', 'type' => 'text', 'required' => true, 'col' => 4],
                'api_key' => ['label' => 'Token do sandbox', 'type' => 'secret', 'col' => 4, 'hint' => 'Só no ambiente de testes.'],
            ],
        ],
        'sicredi' => [
            'label' => 'Sicredi',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://developer.sicredi.com.br',
            'methods' => ['boleto_pix', 'boleto'],
            'pdf' => true,
            'note' => 'API de Cobrança: boleto híbrido (boleto com QR Code Pix) na carteira do escritório na cooperativa.',
            'help' => 'No portal Sicredi Developers: solicite a API de Cobrança e copie o token (x-api-key). No Internet Banking gere o Código de Acesso da API. Código do beneficiário, cooperativa e posto estão no contrato de cobrança.',
            'fields' => [
                'api_key' => ['label' => 'Token do portal (x-api-key)', 'type' => 'secret', 'required' => true],
                'client_secret' => ['label' => 'Código de acesso (Internet Banking)', 'type' => 'secret', 'required' => true],
                'beneficiario' => ['label' => 'Código do beneficiário', 'type' => 'text', 'required' => true, 'col' => 4],
                'cooperativa' => ['label' => 'Cooperativa', 'type' => 'text', 'required' => true, 'col' => 4],
                'posto' => ['label' => 'Posto', 'type' => 'text', 'required' => true, 'col' => 4],
            ],
        ],
        'caixa' => [
            'label' => 'Caixa',
            'kind' => 'banco',
            'pricing' => 'comercial',
            'site' => 'https://www.caixa.gov.br/empresa/cobranca-bancaria',
            'methods' => ['boleto'],
            'pdf' => false,
            'note' => 'Webservice de Cobrança Bancária (SIGCB): registra e consulta o boleto. Não gera Pix. A Caixa só libera o webservice para o IP do servidor cadastrado, por isso não funciona no Laragon sem IP fixo; nesse caso use o arquivo de retorno CNAB 240 para a baixa.',
            'help' => 'Peça à agência a liberação do "Webservice de Cobrança Bancária" para o código de beneficiário e o IP público do servidor.',
            'fields' => [
                'beneficiario' => ['label' => 'Código do beneficiário (7 dígitos)', 'type' => 'text', 'required' => true, 'col' => 6],
                'cnpj' => ['label' => 'CNPJ/CPF do beneficiário', 'type' => 'text', 'required' => true, 'col' => 6],
            ],
        ],
    ];

    /** Credenciais que têm coluna própria; as demais ficam em settings. */
    public const COLUMNS = ['api_key', 'client_id', 'client_secret', 'certificate', 'private_key', 'account_number'];

    public const METHODS = [
        'boleto_pix' => 'Boleto com Pix',
        'boleto' => 'Só boleto',
        'pix' => 'Só Pix',
        'livre' => 'Cliente escolhe (boleto, Pix ou cartão)',
    ];

    public const ENVIRONMENTS = [
        'sandbox' => 'Testes (sandbox, sem dinheiro de verdade)',
        'producao' => 'Produção',
    ];

    /** Opções da NFS-e (gravadas em settings). */
    public const NFSE_DEFAULTS = [
        'nfse_enabled' => false,
        'nfse_auto' => false,
        'service_id' => null,
        'service_code' => null,
        'service_name' => null,
        'iss' => null,
        'retain_iss' => false,
        'description' => 'Honorários advocatícios: {descricao}',
        'observations' => null,
    ];

    protected $fillable = [
        'driver', 'environment', 'client_id', 'account_number', 'fine_percent', 'interest_percent', 'notify_customer',
        'active', 'is_default',
    ];

    protected $hidden = ['api_key', 'client_secret', 'certificate', 'private_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'client_secret' => 'encrypted',
            'certificate' => 'encrypted',
            'private_key' => 'encrypted',
            'fine_percent' => 'decimal:2',
            'interest_percent' => 'decimal:2',
            'notify_customer' => 'boolean',
            'settings' => 'array',
            'active' => 'boolean',
            'is_default' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_test_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->webhook_token ??= Str::random(40);
        });
    }

    public static function forDriver(string $driver): ?self
    {
        return static::where('driver', $driver)->first();
    }

    /** Plataformas prontas para gerar cobranças. @return \Illuminate\Support\Collection<int, self> */
    public static function usable()
    {
        return static::where('active', true)->orderByDesc('is_default')->orderBy('id')->get()->filter->isConfigured()->values();
    }

    /** Conta do Asaas com a emissão de NFS-e ligada. */
    public static function nfse(): ?self
    {
        $p = static::forDriver('asaas');

        return $p && $p->isConfigured() && $p->setting('nfse_enabled') ? $p : null;
    }

    public function isConfigured(): bool
    {
        foreach (self::DRIVERS[$this->driver]['fields'] ?? [] as $name => $f) {
            $required = $f['required'] ?? false;
            if (($required === true || $required === $this->environment) && blank($this->credential($name))) {
                return false;
            }
        }

        return isset(self::DRIVERS[$this->driver]);
    }

    /** Valor de uma credencial (coluna ou settings). */
    public function credential(string $name): mixed
    {
        if (in_array($name, self::COLUMNS, true)) {
            return $this->{$name};
        }

        return ($this->settings ?? [])[$name] ?? null;
    }

    public function info(string $key): mixed
    {
        return self::DRIVERS[$this->driver][$key] ?? null;
    }

    public function getLabelAttribute(): string
    {
        return self::DRIVERS[$this->driver]['label'] ?? $this->driver;
    }

    public function isSandbox(): bool
    {
        return $this->environment !== 'producao';
    }

    /** @return array<string, string> formas de pagamento que a plataforma oferece */
    public function methods(): array
    {
        return array_intersect_key(self::METHODS, array_flip(self::DRIVERS[$this->driver]['methods'] ?? ['boleto_pix']));
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::NFSE_DEFAULTS[$key] ?? null;
    }

    /** Endereço que a plataforma chama quando um pagamento muda (só funciona com o sistema publicado). */
    public function getWebhookUrlAttribute(): string
    {
        return url('/api/cobranca/'.$this->webhook_token);
    }

    public static function label(string $driver): string
    {
        return self::DRIVERS[$driver]['label'] ?? $driver;
    }
}
