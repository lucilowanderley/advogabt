<?php

namespace App\Support\Agenda;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail automático do escritório (convite, lembrete, resumo, teste), com .ics opcional. */
class OfficeMessage extends Mailable
{
    /**
     * @param  list<string>  $lines  parágrafos
     * @param  list<array{title: string, meta?: string, url?: string, late?: bool}>  $items
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $lines = [],
        public array $items = [],
        public ?string $buttonUrl = null,
        public ?string $buttonLabel = null,
        public ?string $ics = null,
        public string $icsMethod = 'PUBLISH',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.office', text: 'emails.office-text');
    }

    public function attachments(): array
    {
        if (! $this->ics) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->ics, $this->icsMethod === 'CANCEL' ? 'cancelamento.ics' : 'convite.ics')
                ->withMime('text/calendar; charset=UTF-8; method='.$this->icsMethod),
        ];
    }
}
