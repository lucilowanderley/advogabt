<?php

namespace App\Support\Integrations;

use App\Models\Task;

/** Aviso de prazos processuais vencendo (evento prazo.vencendo). */
class Deadlines
{
    public static function days(): int
    {
        return max(0, (int) config('services.integrations.deadline_days', 3));
    }

    /** Prazos em aberto que vencem até o fim do dia "hoje + N dias" (inclui os atrasados). */
    public static function upcoming(?int $days = null)
    {
        return Task::query()
            ->with(['users', 'legalCase', 'contact'])
            ->where('is_deadline', true)
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', today()->addDays($days ?? self::days())->endOfDay())
            ->orderBy('due_at');
    }

    /** Envia prazo.vencendo uma vez por prazo. Retorna quantos foram avisados. */
    public static function notify(): int
    {
        if (Webhooks::listeners('prazo.vencendo')->isEmpty()) {
            return 0; // sem destino: não marca, para avisar quando um webhook for criado
        }

        $count = 0;
        self::upcoming()->whereNull('deadline_alerted_at')
            ->where('due_at', '>=', today()->subDays(1))
            ->limit(200)->get()
            ->each(function (Task $t) use (&$count) {
                Webhooks::dispatch('prazo.vencendo', ApiData::task($t));
                $t->forceFill(['deadline_alerted_at' => now()])->saveQuietly();
                $count++;
            });

        return $count;
    }
}
