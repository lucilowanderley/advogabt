<?php

namespace App\Support\Integrations;

use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\Webhook;
use App\Models\WhatsappChannel;
use App\Support\Whatsapp\WhatsappBot;
use Illuminate\Support\Facades\Log;

/** Liga os acontecimentos do sistema aos eventos de webhook. */
class ModelEvents
{
    public static function register(): void
    {
        Webhook::saved(fn () => Webhooks::flush());
        Webhook::deleted(fn () => Webhooks::flush());

        LegalCase::created(fn (LegalCase $c) => Webhooks::dispatch('processo.criado',
            fn () => ApiData::legalCase($c->fresh(['client', 'responsible']) ?? $c)));

        LegalCase::updated(function (LegalCase $c) {
            $changed = array_values(array_diff(array_keys($c->getChanges()), ['updated_at']));
            if (! $changed) {
                return; // só "touch" (ex.: novo andamento)
            }
            $previous = array_intersect_key($c->getOriginal(), array_flip($changed));
            $previous = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $previous);
            Webhooks::dispatch('processo.atualizado', fn () => ApiData::legalCase($c->fresh(['client', 'responsible']) ?? $c)
                + ['changed_fields' => $changed, 'previous' => $previous]);
        });

        CaseMovement::created(function (CaseMovement $m) {
            Webhooks::dispatch('andamento.criado', fn () => ApiData::movement($m->fresh(['legalCase.client', 'user']) ?? $m));

            // WhatsApp: avisa o cliente do novo andamento, se o canal estiver configurado para isso.
            if (! Webhooks::$muted && WhatsappChannel::active()->where('notify_movements', true)->exists()) {
                \Illuminate\Support\defer(function () use ($m) {
                    try {
                        app(WhatsappBot::class)->notifyMovement($m->fresh(['legalCase.client']) ?? $m);
                    } catch (\Throwable $e) {
                        Log::warning('Aviso de andamento pelo WhatsApp falhou: '.$e->getMessage());
                    }
                });
            }
        });

        Task::created(fn (Task $t) => Webhooks::dispatch('tarefa.criada', fn () => self::task($t)));
        Task::updated(function (Task $t) {
            if ($t->wasChanged('status') && $t->status === 'concluida') {
                Webhooks::dispatch('tarefa.concluida', fn () => self::task($t));
            }
        });

        Event::created(fn (Event $e) => Webhooks::dispatch('compromisso.criado',
            fn () => ApiData::event($e->fresh(['legalCase', 'contact', 'user']) ?? $e)));

        Contact::created(fn (Contact $c) => Webhooks::dispatch('contato.criado', fn () => ApiData::contact($c)));
        Contact::updated(function (Contact $c) {
            if ($c->wasChanged('crm_stage')) {
                $from = $c->getOriginal('crm_stage');
                Webhooks::dispatch('contato.etapa_alterada', fn () => ApiData::contact($c) + ['previous_crm_stage' => $from]);
            }
        });

        FinancialEntry::created(function (FinancialEntry $f) {
            Webhooks::dispatch('lancamento.criado', fn () => self::entry($f));
            if ($f->type === 'receita' && $f->paid_at) {
                Webhooks::dispatch('pagamento.recebido', fn () => self::entry($f));
            }
        });
        FinancialEntry::updated(function (FinancialEntry $f) {
            if ($f->type === 'receita' && $f->wasChanged('paid_at') && $f->paid_at && ! $f->getOriginal('paid_at')) {
                Webhooks::dispatch('pagamento.recebido', fn () => self::entry($f));
            }
        });
    }

    private static function task(Task $t): array
    {
        return ApiData::task($t->fresh(['users', 'legalCase', 'contact']) ?? $t);
    }

    private static function entry(FinancialEntry $f): array
    {
        return ApiData::financialEntry($f->fresh(['contact', 'legalCase']) ?? $f);
    }
}
