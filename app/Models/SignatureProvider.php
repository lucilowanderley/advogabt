<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Conta do escritório em uma plataforma de assinatura eletrônica (ClickSign ou D4Sign). */
class SignatureProvider extends Model
{
    public const DRIVERS = [
        'clicksign' => [
            'label' => 'ClickSign',
            'pricing' => 'comercial',
            'site' => 'https://www.clicksign.com',
            'token_help' => 'No ClickSign: Configurações › API › Chaves de acesso (Access Token). O ambiente de testes (sandbox) é gratuito e tem chave própria, criada em sandbox.clicksign.com.',
            'note' => 'Assinatura eletrônica com validade jurídica (MP 2.200-2/2001, art. 10, § 2º e Lei 14.063/2020). O signatário assina pelo link recebido por e-mail, SMS ou WhatsApp, sem instalar nada. Aceita também exigir certificado digital ICP-Brasil.',
        ],
        'd4sign' => [
            'label' => 'D4Sign',
            'pricing' => 'comercial',
            'site' => 'https://www.d4sign.com.br',
            'token_help' => 'Na D4Sign: Perfil › Dev API › gere o Token API e a Crypt Key. O ambiente de testes (sandbox.d4sign.com.br) é gratuito, com conta separada.',
            'note' => 'Assinatura eletrônica com validade jurídica. O documento fica em um cofre da conta; cada signatário recebe o link por e-mail e pode ser obrigado a usar certificado digital ICP-Brasil.',
        ],
    ];

    /** Assinatura com o certificado do próprio advogado/cliente, fora de plataformas. */
    public const MANUAL = [
        'label' => 'Certificado digital ICP-Brasil (assinador próprio)',
        'pricing' => 'gratis',
        'note' => 'Sem plataforma: baixe o PDF, assine com o seu certificado A1/A3 (Assinador SERPRO, Adobe Acrobat, PJeOffice...) ou peça ao cliente para assinar, e anexe aqui o PDF assinado. O sistema confere se o arquivo tem assinatura digital e se ela é ICP-Brasil.',
    ];

    public const ENVIRONMENTS = [
        'sandbox' => 'Testes (sandbox, sem validade jurídica)',
        'producao' => 'Produção',
    ];

    protected $fillable = ['driver', 'environment', 'safe_uuid', 'safe_name', 'active', 'is_default'];

    protected $hidden = ['api_token', 'crypt_key'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'crypt_key' => 'encrypted',
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

    /** Plataformas prontas para envio. @return \Illuminate\Support\Collection<int, self> */
    public static function usable()
    {
        return static::where('active', true)->orderByDesc('is_default')->orderBy('id')->get()->filter->isConfigured()->values();
    }

    public function isConfigured(): bool
    {
        return filled($this->api_token) && ($this->driver !== 'd4sign' || (filled($this->crypt_key) && filled($this->safe_uuid)));
    }

    public function getLabelAttribute(): string
    {
        return self::DRIVERS[$this->driver]['label'] ?? $this->driver;
    }

    public function isSandbox(): bool
    {
        return $this->environment !== 'producao';
    }

    /** Endereço que a plataforma chama quando um documento é assinado (só funciona com o sistema publicado). */
    public function getWebhookUrlAttribute(): string
    {
        return url('/api/assinatura/'.$this->webhook_token);
    }

    public static function label(string $provider): string
    {
        return $provider === 'manual' ? 'ICP-Brasil (assinador próprio)' : (self::DRIVERS[$provider]['label'] ?? $provider);
    }
}
