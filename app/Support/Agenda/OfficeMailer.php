<?php

namespace App\Support\Agenda;

use App\Models\MailLog;
use App\Models\MailSetting;
use App\Support\Ai\CertificateStore;
use Composer\CaBundle\CaBundle;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Envia os e-mails do escritório pela conta SMTP configurada em Integrações › Agenda e e-mail. */
class OfficeMailer
{
    public const MAILER = 'escritorio';

    /** Monta o mailer do Laravel com os dados salvos (sem depender do .env). */
    public static function mailer(MailSetting $s): mixed
    {
        $encryption = $s->encryption ?: 'tls';
        config(['mail.mailers.'.self::MAILER => [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $s->host,
            'port' => (int) $s->port,
            'username' => $s->username ?: null,
            'password' => $s->password ?: null,
            'timeout' => 20,
            'auto_tls' => $encryption !== 'none',
            'require_tls' => $encryption === 'tls',
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]]);

        $manager = app('mail.manager');
        if (method_exists($manager, 'purge')) {
            $manager->purge(self::MAILER);
        }
        $mailer = Mail::mailer(self::MAILER);

        // Mesmo cuidado do Assistente IA: autoridades da Mozilla + as do Windows (antivírus que inspeciona TLS).
        if ($mailer instanceof Mailer) {
            $transport = $mailer->getSymfonyTransport();
            if ($transport instanceof EsmtpTransport) {
                $stream = $transport->getStream();
                $options = $stream->getStreamOptions();
                $options['ssl']['cafile'] = CertificateStore::generated() ?? CaBundle::getBundledCaBundlePath();
                $stream->setStreamOptions($options);
            }
        }

        return $mailer;
    }

    /**
     * Envia e registra no histórico. Retorna null se deu certo, ou a mensagem de erro.
     *
     * @param  array<string, string|null>  $to  e-mail => nome
     */
    public static function send(MailSetting $s, string $kind, array $to, OfficeMessage $message): ?string
    {
        $to = array_filter($to, fn ($name, $email) => filter_var($email, FILTER_VALIDATE_EMAIL), ARRAY_FILTER_USE_BOTH);
        if (! $to) {
            return 'Sem destinatário com e-mail válido.';
        }

        $error = null;
        try {
            $message->from($s->from_address, $s->from_name ?: config('app.name'));
            if ($s->reply_to) {
                $message->replyTo($s->reply_to);
            }
            $recipients = collect($to)->map(fn ($name, $email) => ['email' => $email, 'name' => $name ?: null])->values()->all();
            self::mailer($s)->to($recipients)->send($message);
        } catch (\Throwable $e) {
            $error = self::explain($e->getMessage());
            Log::warning('E-mail do escritório não enviado: '.$e->getMessage());
        }

        MailLog::create([
            'kind' => $kind,
            'to' => mb_strimwidth(implode(', ', array_keys($to)), 0, 250, '…'),
            'subject' => mb_strimwidth($message->subjectLine, 0, 250, '…'),
            'success' => $error === null,
            'error' => $error,
        ]);

        return $error;
    }

    /** Traduz os erros mais comuns do SMTP para o usuário. */
    public static function explain(string $error): string
    {
        $hint = match (true) {
            (bool) preg_match('/\b535\b|Username and Password not accepted|authentication failed|Authentication unsuccessful|BadCredentials/i', $error)
                => 'O servidor recusou o usuário ou a senha. No Gmail e no Outlook use uma senha de app (a senha normal da conta não funciona).',
            (bool) preg_match('/SmtpClientAuthentication is disabled|basic authentication is disabled/i', $error)
                => 'O SMTP autenticado está desligado nessa caixa do Microsoft 365. O administrador precisa ativá-lo no centro de administração do Exchange.',
            (bool) preg_match('/certificate|SSL operation failed|crypto/i', $error)
                => 'Falha no certificado TLS. Se houver antivírus que inspeciona conexões, rode "php artisan ai:certificados" no servidor.',
            (bool) preg_match('/Connection could not be established|Connection refused|timed out|getaddrinfo|php_network/i', $error)
                => 'Não foi possível conectar ao servidor. Confira o endereço, a porta e a criptografia, e se o firewall/antivírus libera a porta.',
            (bool) preg_match('/\b55[034]\b.*(sender|from|relay)|not allowed to send|Sender address rejected/i', $error)
                => 'O servidor recusou o remetente. O "E-mail do remetente" precisa ser o da própria conta (ou um alias autorizado).',
            default => null,
        };

        $error = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $error)), 0, 400, '…');

        return $hint ? $hint.' ('.$error.')' : $error;
    }
}
