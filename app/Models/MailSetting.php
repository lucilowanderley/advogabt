<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Conta de e-mail do escritório usada nos envios automáticos (SMTP com senha de app). */
class MailSetting extends Model
{
    public const PROVIDERS = [
        'gmail' => [
            'label' => 'Gmail / Google Workspace',
            'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Use uma senha de app: ative a verificação em duas etapas na conta Google e gere a senha em myaccount.google.com/apppasswords. A senha normal da conta não funciona.',
            'link' => 'https://myaccount.google.com/apppasswords',
        ],
        'outlook' => [
            'label' => 'Outlook / Microsoft 365',
            'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Contas Microsoft 365: o administrador precisa liberar o "SMTP autenticado" da caixa no centro de administração do Exchange. Contas com verificação em duas etapas usam uma senha de app. A Microsoft vem desligando o login por senha no SMTP; se o teste recusar a senha, use o SMTP do provedor do seu domínio.',
            'link' => 'https://account.live.com/proofs/AppPassword',
        ],
        'smtp' => [
            'label' => 'Outro provedor (SMTP)',
            'host' => '', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Servidor SMTP do provedor do domínio do escritório (Hostinger, Locaweb, KingHost, Zoho, Titan...) ou de um serviço de envio (Brevo, SendGrid, Amazon SES). Os dados ficam no painel do provedor.',
            'link' => null,
        ],
    ];

    public const ENCRYPTIONS = [
        'tls' => 'STARTTLS (porta 587)',
        'ssl' => 'SSL/TLS (porta 465)',
        'none' => 'Sem criptografia (não recomendado)',
    ];

    /** Tipos de compromisso em que o cliente vinculado pode receber convite e lembrete. */
    public const CLIENT_EVENT_TYPES = ['audiencia', 'reuniao', 'pericia'];

    protected $fillable = [
        'provider', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'reply_to',
        'active', 'send_invites', 'invite_clients', 'remind_events', 'remind_hours', 'remind_clients',
        'daily_digest', 'digest_hour',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'active' => 'boolean',
            'send_invites' => 'boolean',
            'invite_clients' => 'boolean',
            'remind_events' => 'boolean',
            'remind_clients' => 'boolean',
            'daily_digest' => 'boolean',
            'last_digest_on' => 'date',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::orderBy('id')->first();
    }

    /** Configuração pronta para enviar, se o envio estiver ligado. */
    public static function enabled(): ?self
    {
        $s = static::current();

        return $s?->active && $s->isConfigured() ? $s : null;
    }

    public static function defaults(): self
    {
        return new self([
            'provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'from_name' => config('app.name'), 'active' => true, 'send_invites' => true, 'invite_clients' => false,
            'remind_events' => true, 'remind_hours' => 24, 'remind_clients' => false, 'daily_digest' => true, 'digest_hour' => 7,
        ]);
    }

    public function isConfigured(): bool
    {
        return filled($this->host) && filled($this->from_address);
    }

    public function getProviderLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider]['label'] ?? $this->provider;
    }
}
