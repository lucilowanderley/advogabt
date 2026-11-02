<?php

namespace App\Support\Cloud;

use App\Models\CloudFolder;
use App\Models\CloudStorage;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\WhatsappMessage;
use App\Support\Integrations\Webhooks;
use Illuminate\Support\Facades\Log;

/** Cria as pastas na nuvem quando surgem clientes e processos, e copia os arquivos do WhatsApp. */
class CloudEvents
{
    public static function register(): void
    {
        LegalCase::created(fn (LegalCase $c) => self::autoCreate($c));
        Contact::created(function (Contact $c) {
            if ($c->category === 'cliente') {
                self::autoCreate($c);
            }
        });
        Contact::updated(function (Contact $c) {
            if ($c->wasChanged('category') && $c->category === 'cliente') {
                self::autoCreate($c);
            }
        });

        // A pasta continua na nuvem; só o vínculo é apagado.
        LegalCase::deleted(fn (LegalCase $c) => CloudFolder::where('owner_type', 'processo')->where('owner_id', $c->id)->delete());
        Contact::deleted(fn (Contact $c) => CloudFolder::where('owner_type', 'contato')->where('owner_id', $c->id)->delete());
    }

    private static function autoCreate(LegalCase|Contact $owner): void
    {
        if (Webhooks::$muted) {
            return; // carga inicial / seeders
        }
        $storage = CloudStorage::connected();
        if (! $storage?->auto_create) {
            return;
        }
        \Illuminate\Support\defer(function () use ($storage, $owner) {
            try {
                app(CloudFolders::class)->ensure($storage, $owner->fresh() ?? $owner);
            } catch (\Throwable $e) {
                Log::warning('Pasta na nuvem não criada: '.$e->getMessage());
            }
        });
    }

    /** Chamado quando um arquivo do cliente chega pelo WhatsApp. */
    public static function whatsappMedia(WhatsappMessage $message, ?int $contactId): void
    {
        if (! $contactId || ! $message->media_path) {
            return;
        }
        $storage = CloudStorage::connected();
        if (! $storage?->copy_whatsapp) {
            return;
        }
        \Illuminate\Support\defer(function () use ($storage, $message, $contactId) {
            try {
                if ($contact = Contact::find($contactId)) {
                    app(CloudFolders::class)->copyWhatsapp($storage, $message, $contact);
                }
            } catch (\Throwable $e) {
                Log::warning('Arquivo do WhatsApp não copiado para a nuvem: '.$e->getMessage());
            }
        });
    }
}
