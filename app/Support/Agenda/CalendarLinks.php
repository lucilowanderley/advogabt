<?php

namespace App\Support\Agenda;

use App\Models\Event;
use App\Support\Options;

/** Links "adicionar à agenda" de um compromisso (Google Agenda e Outlook na web). */
class CalendarLinks
{
    public static function google(Event $event): string
    {
        $dates = $event->all_day
            ? $event->starts_at->format('Ymd').'/'.$event->starts_at->copy()->addDay()->format('Ymd')
            : Ics::utc($event->starts_at).'/'.Ics::utc(self::end($event));

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $dates,
            'details' => self::details($event),
            'location' => (string) $event->location,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** office = Microsoft 365 (trabalho/escola); live = Outlook.com pessoal. */
    public static function outlook(Event $event, string $host = 'office'): string
    {
        $base = $host === 'live' ? 'https://outlook.live.com' : 'https://outlook.office.com';
        $format = $event->all_day ? 'Y-m-d' : 'Y-m-d\TH:i:sP';

        return $base.'/calendar/0/deeplink/compose?'.http_build_query(array_filter([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $event->title,
            'startdt' => $event->starts_at->format($format),
            'enddt' => ($event->all_day ? $event->starts_at->copy()->addDay() : self::end($event))->format($format),
            'allday' => $event->all_day ? 'true' : null,
            'body' => self::details($event),
            'location' => $event->location,
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    private static function end(Event $event)
    {
        return $event->ends_at && $event->ends_at->gt($event->starts_at) ? $event->ends_at : $event->starts_at->copy()->addHour();
    }

    private static function details(Event $event): string
    {
        return implode("\n", array_filter([
            Options::label(Options::EVENT_TYPES, $event->type),
            $event->legalCase ? 'Processo: '.$event->legalCase->display_name : null,
            $event->contact ? 'Cliente/contato: '.$event->contact->name : null,
            $event->notes,
            route('events.edit', $event),
        ]));
    }
}
