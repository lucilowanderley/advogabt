<?php

namespace App\Support\Agenda;

use App\Models\Event;
use App\Models\MailSetting;
use App\Models\Task;
use App\Models\User;
use App\Support\Integrations\Deadlines;
use App\Support\Integrations\Webhooks;
use App\Support\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * E-mails automáticos da agenda: convite (.ics) quando um compromisso é marcado ou alterado,
 * cancelamento quando é excluído, lembrete antes do compromisso e resumo diário dos prazos.
 * O convite é o que leva o compromisso para o Google Agenda/Outlook na hora, mesmo com o
 * sistema rodando só no computador do escritório (Laragon).
 */
class AgendaMail
{
    /** Campos que, alterados, geram um novo convite. */
    public const INVITE_FIELDS = ['title', 'type', 'starts_at', 'ends_at', 'all_day', 'location', 'user_id', 'contact_id', 'legal_case_id'];

    public static function register(): void
    {
        Event::created(fn (Event $e) => self::later(fn () => self::invite($e)));
        Event::updated(function (Event $e) {
            if ($e->wasChanged(self::INVITE_FIELDS)) {
                self::later(fn () => self::invite($e));
            }
        });
        Event::deleted(function (Event $e) {
            if (filled($e->invited_emails)) {
                $copy = clone $e;
                self::later(fn () => self::cancel($copy));
            }
        });
    }

    /** Há envio automático configurado? (para a rotina pelo uso do sistema) */
    public static function hasWork(): bool
    {
        return Cache::remember('agenda-mail-active', now()->addMinutes(5), fn () => (bool) MailSetting::enabled());
    }

    public static function forget(): void
    {
        Cache::forget('agenda-mail-active');
    }

    public static function run(): array
    {
        $s = MailSetting::enabled();
        if (! $s) {
            return ['reminders' => 0, 'digests' => 0];
        }

        return ['reminders' => self::reminders($s), 'digests' => self::digest($s)];
    }

    // --- Convites ------------------------------------------------------------

    /** @return array<string, string|null> e-mail => nome */
    public static function recipients(Event $event, MailSetting $s, bool $includeClient): array
    {
        $to = [];
        $user = $event->user;
        if ($user && $user->active && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $to[strtolower($user->email)] = $user->name;
        }
        $contact = $event->contact;
        if ($includeClient && $contact && in_array($event->type, MailSetting::CLIENT_EVENT_TYPES, true)
            && filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
            $to[strtolower($contact->email)] ??= $contact->name;
        }

        return $to;
    }

    public static function invite(Event $event): int
    {
        $s = MailSetting::enabled();
        if (! $s || ! $s->send_invites || Webhooks::$muted) {
            return 0;
        }
        $event->loadMissing(['user', 'contact', 'legalCase']);
        $to = self::recipients($event, $s, $s->invite_clients);
        $before = self::emails($event->invited_emails);
        $removed = array_diff($before, array_keys($to));
        if (! $to && ! $removed) {
            return 0;
        }

        $event->forceFill(['sequence' => (int) $event->sequence + 1, 'invited_emails' => implode(',', array_keys($to)) ?: null])->saveQuietly();

        $sent = 0;
        foreach ($removed as $email) {
            self::sendCalendar($s, $event, 'CANCEL', $email, null);
        }
        foreach ($to as $email => $name) {
            $sent += self::sendCalendar($s, $event, 'REQUEST', $email, $name) ? 1 : 0;
        }

        return $sent;
    }

    public static function cancel(Event $event): int
    {
        $s = MailSetting::enabled();
        if (! $s || ! $s->send_invites) {
            return 0;
        }
        $event->sequence = (int) $event->sequence + 1;
        $sent = 0;
        foreach (self::emails($event->invited_emails) as $email) {
            $sent += self::sendCalendar($s, $event, 'CANCEL', $email, null) ? 1 : 0;
        }

        return $sent;
    }

    private static function sendCalendar(MailSetting $s, Event $event, string $method, string $email, ?string $name): bool
    {
        $isStaff = User::where('email', $email)->exists();
        $type = Options::label(Options::EVENT_TYPES, $event->type);
        $when = self::when($event);
        $cancel = $method === 'CANCEL';
        $ics = Ics::single($event, $method, $s->from_address, [$email => $name], ! $isStaff);

        $lines = $cancel
            ? ['Este compromisso foi cancelado ou não está mais com você.']
            : [($event->sequence > 1 ? 'Compromisso atualizado. ' : '').'Abra o convite (ou o anexo .ics) para colocá-lo na sua agenda do Google, Outlook ou iPhone.'];
        $items = [['title' => $event->title, 'meta' => implode(' · ', array_filter([$type, $when, $event->location]))]];
        if ($isStaff && $event->legalCase) {
            $items[] = ['title' => 'Processo: '.$event->legalCase->display_name, 'url' => route('cases.show', $event->legalCase)];
        }

        $message = new OfficeMessage(
            subjectLine: ($cancel ? 'Cancelado: ' : ($event->sequence > 1 ? 'Atualizado: ' : 'Convite: ')).$type.' · '.$event->title.' · '.$when,
            heading: $cancel ? 'Compromisso cancelado' : ($event->sequence > 1 ? 'Compromisso atualizado' : 'Novo compromisso na agenda'),
            lines: $lines,
            items: $items,
            buttonUrl: $isStaff && ! $cancel ? route('events.edit', $event) : null,
            buttonLabel: 'Ver na agenda',
            ics: $ics,
            icsMethod: $method,
        );

        return OfficeMailer::send($s, $cancel ? 'cancelamento' : 'convite', [$email => $name], $message) === null;
    }

    // --- Lembretes -----------------------------------------------------------

    public static function reminders(MailSetting $s): int
    {
        if (! $s->remind_events) {
            return 0;
        }
        $hours = max(1, (int) $s->remind_hours);
        $sent = 0;

        Event::with(['user', 'contact', 'legalCase'])
            ->whereNull('reminded_at')
            ->where('starts_at', '>=', today())
            ->where('starts_at', '<=', now()->addHours($hours))
            ->orderBy('starts_at')->limit(100)->get()
            ->each(function (Event $event) use ($s, &$sent) {
                if (! $event->all_day && $event->starts_at->lt(now())) {
                    $event->forceFill(['reminded_at' => now()])->saveQuietly(); // já começou

                    return;
                }
                $event->forceFill(['reminded_at' => now()])->saveQuietly();
                foreach (self::recipients($event, $s, $s->remind_clients) as $email => $name) {
                    $isStaff = $event->user && strtolower((string) $event->user->email) === $email;
                    $type = Options::label(Options::EVENT_TYPES, $event->type);
                    $message = new OfficeMessage(
                        subjectLine: 'Lembrete: '.$type.' · '.$event->title.' · '.self::when($event),
                        heading: 'Lembrete de '.mb_strtolower($type),
                        lines: [$isStaff ? 'Você tem este compromisso em breve:' : 'Olá, '.explode(' ', trim((string) $name))[0].'. Lembramos do seu compromisso com o escritório:'],
                        items: [['title' => $event->title, 'meta' => implode(' · ', array_filter([self::when($event), $event->location]))]],
                        buttonUrl: $isStaff ? route('events.edit', $event) : null,
                        buttonLabel: 'Ver na agenda',
                    );
                    $sent += OfficeMailer::send($s, 'lembrete', [$email => $name], $message) === null ? 1 : 0;
                }
            });

        return $sent;
    }

    // --- Resumo diário -------------------------------------------------------

    public static function digest(MailSetting $s, bool $force = false): int
    {
        if (! $s->daily_digest && ! $force) {
            return 0;
        }
        if (! $force && (now()->hour < (int) $s->digest_hour || $s->last_digest_on?->isToday())) {
            return 0;
        }
        $s->forceFill(['last_digest_on' => today()])->saveQuietly();

        $days = max(1, Deadlines::days());
        $sent = 0;
        User::where('active', true)->orderBy('id')->get()->each(function (User $user) use ($s, $days, &$sent) {
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $events = Event::with('legalCase')->where('user_id', $user->id)
                ->whereBetween('starts_at', [today(), today()->endOfDay()])->orderBy('starts_at')->get();
            $tasks = Task::with('legalCase')->open()->forUser($user->id)->whereNotNull('due_at')
                ->where('due_at', '<=', today()->addDays($days)->endOfDay())
                ->orderBy('due_at')->limit(50)->get();
            if ($events->isEmpty() && $tasks->isEmpty()) {
                return;
            }

            $items = [];
            foreach ($events as $e) {
                $items[] = ['title' => ($e->all_day ? 'Dia inteiro' : $e->starts_at->format('H:i')).' · '.$e->title,
                    'meta' => implode(' · ', array_filter([Options::label(Options::EVENT_TYPES, $e->type), $e->location, $e->legalCase?->display_name])),
                    'url' => route('events.edit', $e)];
            }
            foreach ($tasks as $t) {
                $late = $t->due_at->lt(today());
                $items[] = ['title' => ($t->is_deadline ? 'Prazo: ' : 'Tarefa: ').$t->title,
                    'meta' => implode(' · ', array_filter([($late ? 'Atrasado desde ' : 'Vence ').self::day($t->due_at), $t->legalCase?->display_name])),
                    'url' => route('tasks.show', $t), 'late' => $late];
            }

            $message = new OfficeMessage(
                subjectLine: 'Sua agenda de hoje · '.today()->translatedFormat('d \d\e F'),
                heading: 'Bom dia, '.$user->first_name,
                lines: [trim(($events->count() ? $events->count().' compromisso(s) hoje. ' : '').($tasks->count() ? $tasks->count().' tarefa(s)/prazo(s) vencendo até '.self::day(today()->addDays($days)).' ou atrasados.' : ''))],
                items: $items,
                buttonUrl: route('dashboard'),
                buttonLabel: 'Abrir o '.config('app.name'),
            );
            $sent += OfficeMailer::send($s, 'resumo', [$user->email => $user->name], $message) === null ? 1 : 0;
        });

        return $sent;
    }

    // --- Apoio ---------------------------------------------------------------

    public static function when(Event $event): string
    {
        return self::day($event->starts_at).($event->all_day ? ' (dia inteiro)' : ' às '.$event->starts_at->format('H:i'));
    }

    private static function day($date): string
    {
        return $date->translatedFormat('D, d/m/Y');
    }

    /** @return list<string> */
    private static function emails(?string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
    }

    /** Envia depois da resposta, para o usuário não esperar o SMTP. */
    private static function later(\Closure $job): void
    {
        if (Webhooks::$muted || ! MailSetting::enabled()) {
            return;
        }
        $run = function () use ($job) {
            try {
                $job();
            } catch (\Throwable $e) {
                Log::warning('E-mail da agenda falhou: '.$e->getMessage());
            }
        };
        function_exists('Illuminate\Support\defer') ? \Illuminate\Support\defer($run) : app()->terminating($run);
    }
}
