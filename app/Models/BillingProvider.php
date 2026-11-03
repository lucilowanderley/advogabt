<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Conta do escritório em uma plataforma de cobrança (Asaas ou Banco Inter). */
class BillingProvider extends Model
{
    public const DRIVERS = [
        'asaas' => [
            'label' => 'Asaas',
            'pricing' => 'comercial',
            'site' => 'https://www.asaas.com',
            'methods' => ['boleto_pix', 'pix', 'livre'],
            'nfse' => true,
            'key_help' => 'No Asaas: Integrações › Chaves de API › Gerar chave. O ambiente de testes (sandbox.asaas.com) é gratuito e tem conta e chave próprias (começam com $aact_hmlg_).',
            'note' => 'Conta de pagamentos com boleto, Pix e cartão. Cobra uma tarifa por boleto/Pix recebido, sem mensalidade. Também emite a NFS-e da prefeitura do escritório.',
        ],
        'inter' => [
            'label' => 'Banco Inter',
            'pricing' => 'gratis',
            'site' => 'https://developers.inter.co',
            'methods' => ['boleto_pix'],
            'nfse' => false,
            'key_help' => 'No Internet Banking PJ do Inter: Soluções para sua empresa › Nova Integração. Marque "Emissão e consulta de boletos" (boleto-cobranca.read e .write), baixe a chave (.key) e o certificado (.crt) e copie o Client ID e o Client Secret.',
            'note' => 'Boleto com QR Code Pix (BolePix) emitido pela conta PJ do escritório. A conta digital PJ do Inter tem cota de boletos gratuitos por mês.',
        ],
    ];

    public const METHODS = [
        'boleto_pix' => 'Boleto com Pix',
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
        return match ($this->driver) {
            'asaas' => filled($this->api_key),
            'inter' => filled($this->client_id) && filled($this->client_secret) && filled($this->certificate) && filled($this->private_key),
            default => false,
        };
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
