<?php

namespace App\Support\Agenda;

use App\Models\Event;
use App\Models\Task;
use App\Support\Options;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Arquivos iCalendar (.ics, RFC 5545): assinatura da agenda, convites por e-mail
 * e download de um compromisso. Horários vão em UTC, que todo app de agenda entende.
 */
class Ics
{
    /** Calendário completo para assinatura (feed). */
    public static function calendar(string $name, iterable $events, iterable $tasks = []): string
    {
        $lines = self::header($name, 'PUBLISH');
        $lines[] = 'X-PUBLISHED-TTL:PT1H';
        $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
        foreach ($events as $event) {
            array_push($lines, ...self::eventLines($event));
        }
        foreach ($tasks as $task) {
            array_push($lines, ...self::taskLines($task));
        }
        $lines[] = 'END:VCALENDAR';

        return self::render($lines);
    }

    /**
     * Um compromisso só. method: PUBLISH (download), REQUEST (convite) ou CANCEL (cancelamento).
     * Nos convites o escritório é o organizador e os destinatários são os participantes.
     */
    public static function single(Event $event, string $method = 'PUBLISH', ?string $organizer = null, array $attendees = [], bool $forClient = false): string
    {
        $lines = self::header(config('app.name'), $method);
        $extra = [];
        if ($method !== 'PUBLISH' && $organizer) {
            $extra[] = 'ORGANIZER;CN='.self::param(config('app.name')).':mailto:'.$organizer;
            foreach ($attendees as $email => $name) {
                $extra[] = 'ATTENDEE;CN='.self::param($name ?: $email).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:'.$email;
            }
        }
        if ($method === 'CANCEL') {
            $extra[] = 'STATUS:CANCELLED';
        }
        array_push($lines, ...self::eventLines($event, $extra, $forClient));
        $lines[] = 'END:VCALENDAR';

        return self::render($lines);
    }

    public static function uid(Event|Task $item): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'advogabt';

        return ($item instanceof Event ? 'evento-' : 'tarefa-').$item->id.'@'.$host;
    }

    private static function header(string $name, string $method): array
    {
        return [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ADVOGABT//Agenda do escritorio//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'X-WR-CALNAME:'.self::text($name),
            'X-WR-TIMEZONE:'.config('app.timezone'),
        ];
    }

    /** forClient: versão para o cliente, sem observações internas nem links do sistema. */
    private static function eventLines(Event $event, array $extra = [], bool $forClient = false): array
    {
        $type = Options::label(Options::EVENT_TYPES, $event->type);
        $description = $forClient ? array_filter([
            $type,
            $event->legalCase?->number ? 'Processo: '.$event->legalCase->number : null,
            $event->user ? 'Responsável: '.$event->user->name : null,
            config('app.name'),
        ]) : array_filter([
            $type,
            $event->legalCase ? 'Processo: '.$event->legalCase->display_name : null,
            $event->contact ? 'Cliente/contato: '.$event->contact->name : null,
            $event->user ? 'Responsável: '.$event->user->name : null,
            $event->notes,
            route('events.edit', $event),
        ]);

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::uid($event),
            'DTSTAMP:'.self::utc(now()),
            'SEQUENCE:'.(int) $event->sequence,
            'SUMMARY:'.self::text(self::prefix($event->type).$event->title),
        ];
        if ($event->all_day) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->starts_at->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->starts_at->copy()->addDay()->format('Ymd');
            $lines[] = 'TRANSP:TRANSPARENT';
        } else {
            $end = $event->ends_at && $event->ends_at->gt($event->starts_at) ? $event->ends_at : $event->starts_at->copy()->addHour();
            $lines[] = 'DTSTART:'.self::utc($event->starts_at);
            $lines[] = 'DTEND:'.self::utc($end);
        }
        if ($event->location) {
            $lines[] = 'LOCATION:'.self::text($event->location);
        }
        $lines[] = 'DESCRIPTION:'.self::text(implode("\n", $description));
        $lines[] = 'CATEGORIES:'.self::text($type);
        if (! $forClient) {
            $lines[] = 'URL:'.route('events.edit', $event);
        }
        if ($event->updated_at) {
            $lines[] = 'LAST-MODIFIED:'.self::utc($event->updated_at);
        }
        array_push($lines, ...$extra);
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function taskLines(Task $task): array
    {
        $due = $task->due_at;
        $label = $task->is_deadline ? 'Prazo' : 'Tarefa';
        $description = array_filter([
            $task->is_deadline ? 'Prazo processual' : 'Tarefa',
            $task->legalCase ? 'Processo: '.$task->legalCase->display_name : null,
            $task->contact ? 'Cliente/contato: '.$task->contact->name : null,
            $task->relationLoaded('users') && $task->users->isNotEmpty() ? 'Responsáveis: '.$task->users->pluck('name')->join(', ') : null,
            $task->description,
            route('tasks.show', $task),
        ]);

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::uid($task),
            'DTSTAMP:'.self::utc(now()),
            'SUMMARY:'.self::text($label.': '.$task->title),
        ];
        if ($due->format('H:i') === '00:00') {
            $lines[] = 'DTSTART;VALUE=DATE:'.$due->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$due->copy()->addDay()->format('Ymd');
            $lines[] = 'TRANSP:TRANSPARENT';
        } else {
            $lines[] = 'DTSTART:'.self::utc($due);
            $lines[] = 'DTEND:'.self::utc($due->copy()->addMinutes(30));
        }
        $lines[] = 'DESCRIPTION:'.self::text(implode("\n", $description));
        $lines[] = 'CATEGORIES:'.$label;
        $lines[] = 'URL:'.route('tasks.show', $task);
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function prefix(string $type): string
    {
        return match ($type) {
            'audiencia' => 'Audiência: ',
            'pericia' => 'Perícia: ',
            'prazo' => 'Prazo: ',
            default => '',
        };
    }

    public static function utc(CarbonInterface $date): string
    {
        return Carbon::instance($date)->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Texto escapado (RFC 5545 3.3.11). */
    public static function text(?string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /** Valor de parâmetro (CN=...): entre aspas, sem aspas internas. */
    private static function param(string $value): string
    {
        return '"'.str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value).'"';
    }

    /** Junta as linhas com CRLF, dobrando as maiores que 75 bytes sem quebrar caracteres UTF-8. */
    private static function render(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $first = true;
            while (strlen($line) > ($first ? 75 : 74)) {
                $max = $first ? 75 : 74;
                $cut = mb_strcut($line, 0, $max, 'UTF-8');
                $out .= ($first ? '' : ' ').$cut."\r\n";
                $line = substr($line, strlen($cut));
                $first = false;
            }
            $out .= ($first ? '' : ' ').$line."\r\n";
        }

        return $out;
    }
}
