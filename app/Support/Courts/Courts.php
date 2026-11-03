<?php

namespace App\Support\Courts;

use App\Models\CaseMovement;
use App\Models\CourtLawyer;
use App\Models\CourtPublication;
use App\Models\CourtSetting;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Support\Integrations\Webhooks;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Integração com os tribunais.
 *
 * - Datajud (CNJ): consulta os movimentos dos processos cadastrados e registra os novos
 *   como andamentos (origem "datajud"). A primeira importação de cada processo é silenciosa
 *   (não dispara webhook nem aviso ao cliente pelo WhatsApp).
 * - DJEN: captura as intimações e publicações das OABs do escritório, vincula ao processo
 *   pelo número CNJ e cria o prazo com a data sugerida em dias úteis.
 */
class Courts
{
    /** Intervalo mínimo entre consultas ao DJEN da mesma OAB. */
    public const DJEN_EVERY_HOURS = 3;

    /** Na primeira consulta de uma OAB, busca as publicações dos últimos dias. */
    public const DJEN_BACKFILL_DAYS = 7;

    /** Processos consultados no Datajud por execução da rotina. */
    public const DATAJUD_BATCH = 20;

    public const GRAUS = [
        'G1' => '1º grau', 'G2' => '2º grau', 'JE' => 'Juizado Especial', 'TR' => 'Turma Recursal',
        'TRU' => 'Turma Regional de Uniformização', 'TNU' => 'Turma Nacional de Uniformização', 'SUP' => 'Tribunal Superior', 'CJF' => 'CJF',
    ];

    // --- Rotina ----------------------------------------------------------------

    public static function hasWork(): bool
    {
        $s = CourtSetting::query()->first();

        return $s && ($s->datajud_enabled || ($s->djen_enabled && CourtLawyer::where('active', true)->exists()));
    }

    /** @return array{publications: int, movements: int} */
    public static function run(bool $force = false): array
    {
        $out = ['publications' => 0, 'movements' => 0];
        $s = CourtSetting::current();
        if (! $s->exists) {
            return $out;
        }

        if ($s->djenOn()) {
            $lawyers = CourtLawyer::where('active', true)
                ->when(! $force, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subHours(self::DJEN_EVERY_HOURS))))
                ->get();
            foreach ($lawyers as $lawyer) {
                try {
                    $out['publications'] += self::fetchPublications($lawyer, $s);
                } catch (CourtException $e) {
                    if ($e->getCode() === CourtException::CONNECTION) {
                        break;
                    }
                }
            }
            self::linkOrphans($s);
        }

        if ($s->datajudOn()) {
            foreach (self::dueCases($s)->limit(self::DATAJUD_BATCH)->get() as $case) {
                try {
                    $out['movements'] += self::syncCase($case, $s);
                } catch (CourtException $e) {
                    if ($e->getCode() === CourtException::CONNECTION) {
                        break;
                    }
                }
            }
        }

        $s->forceFill(['last_run_at' => now()])->save();

        return $out;
    }

    /** Processos acompanhados cuja última consulta ao Datajud já passou do intervalo. */
    public static function dueCases(CourtSetting $s): Builder
    {
        return self::monitoredCases()
            ->where(fn (Builder $q) => $q->whereNull('datajud_checked_at')->orWhere('datajud_checked_at', '<', now()->subHours(max(1, (int) $s->datajud_hours))))
            ->orderByRaw('datajud_checked_at is not null')
            ->orderBy('datajud_checked_at');
    }

    public static function monitoredCases(): Builder
    {
        return LegalCase::query()
            ->where('datajud_sync', true)
            ->whereNotNull('number')->where('number', '!=', '')
            ->whereNotIn('status', ['arquivado', 'encerrado']);
    }

    // --- Datajud ---------------------------------------------------------------

    /** Consulta o processo no Datajud e registra os movimentos novos. Retorna quantos andamentos entraram. */
    public static function syncCase(LegalCase $case, ?CourtSetting $s = null): int
    {
        $s ??= CourtSetting::current();
        $digits = Cnj::digits($case->number);

        try {
            if (! Cnj::isValid($digits)) {
                throw new CourtException('O número "'.$case->number.'" não está no padrão CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) ou o dígito verificador não confere.');
            }
            $sources = app(DatajudClient::class)->search($digits, $s->apiKey());
        } catch (CourtException $e) {
            $case->forceFill(['datajud_checked_at' => now(), 'datajud_error' => Str::limit($e->getMessage(), 1000)])->saveQuietly();
            throw $e;
        }

        if (! $sources) {
            $case->forceFill([
                'datajud_checked_at' => now(),
                'datajud_error' => 'Processo não encontrado no Datajud. Processos novos podem levar alguns dias para aparecer; processos em segredo de justiça não aparecem.',
            ])->saveQuietly();

            return 0;
        }

        self::fillCaseData($case, $sources);
        $created = self::importMovements($case, $sources, $s->datajud_notify);

        $updated = collect($sources)->map(fn ($src) => self::parseDate($src['dataHoraUltimaAtualizacao'] ?? null))->filter()->max();
        $case->forceFill(['datajud_checked_at' => now(), 'datajud_updated_at' => $updated, 'datajud_error' => null])->saveQuietly();

        return $created;
    }

    /** @param list<array<string, mixed>> $sources */
    private static function importMovements(LegalCase $case, array $sources, bool $notify): int
    {
        $graus = collect($sources)->pluck('grau')->filter()->unique();
        $known = $case->movements()->getQuery()->whereNotNull('external_id')->pluck('external_id')->flip();
        $firstImport = ! $case->movements()->getQuery()->where('source', 'datajud')->exists();

        $new = [];
        foreach ($sources as $src) {
            $grau = $src['grau'] ?? null;
            foreach ((array) ($src['movimentos'] ?? []) as $mv) {
                $date = self::parseDate($mv['dataHora'] ?? null);
                $name = trim((string) ($mv['nome'] ?? ''));
                if (! $date || $name === '') {
                    continue;
                }
                $complements = collect((array) ($mv['complementosTabelados'] ?? []))
                    ->map(fn ($c) => is_array($c) ? trim((string) (($c['nome'] ?? '') ?: ($c['valor'] ?? ''))) : '')
                    ->filter()->unique()->implode('; ');
                $description = $name.($complements !== '' ? " ({$complements})" : '');
                if ($graus->count() > 1 && $grau) {
                    $description = '['.(self::GRAUS[$grau] ?? $grau).'] '.$description;
                }
                $id = 'dj-'.sha1(implode('|', [$case->id, $grau, $mv['codigo'] ?? '', $date->toIso8601String(), $description]));
                if (isset($known[$id]) || isset($new[$id])) {
                    continue;
                }
                $new[$id] = ['date' => $date, 'description' => $description];
            }
        }

        uasort($new, fn ($a, $b) => $a['date'] <=> $b['date']);
        $mute = $firstImport || ! $notify;
        $wasMuted = Webhooks::$muted;
        Webhooks::$muted = $wasMuted || $mute;
        try {
            foreach ($new as $id => $m) {
                CaseMovement::create([
                    'legal_case_id' => $case->id,
                    'date' => $m['date']->toDateString(),
                    'description' => Str::limit($m['description'], 5000),
                    'source' => 'datajud',
                    'external_id' => $id,
                ]);
            }
        } finally {
            Webhooks::$muted = $wasMuted;
        }

        return count($new);
    }

    /** Completa no processo os campos que estiverem vazios (tribunal, vara, classe, data de distribuição). */
    private static function fillCaseData(LegalCase $case, array $sources): void
    {
        $main = collect($sources)->sortBy(fn ($s) => ($s['grau'] ?? '') === 'G1' || ($s['grau'] ?? '') === 'JE' ? 0 : 1)->first();
        $fill = array_filter([
            'court' => $case->court ? null : ($main['tribunal'] ?? Cnj::court($case->number)),
            'court_division' => $case->court_division ? null : ($main['orgaoJulgador']['nome'] ?? null),
            'action_type' => $case->action_type ? null : ($main['classe']['nome'] ?? null),
            'distributed_at' => $case->distributed_at ? null : self::parseDate($main['dataAjuizamento'] ?? null)?->toDateString(),
        ]);
        if ($fill) {
            $case->forceFill(array_map(fn ($v) => is_string($v) ? Str::limit($v, 250, '') : $v, $fill))->save();
        }
    }

    /** Datas do Datajud vêm como ISO 8601 ou "AAAAMMDDhhmmss", conforme o tribunal. */
    public static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        try {
            if (preg_match('/^\d{14}$/', $value)) {
                return Carbon::createFromFormat('YmdHis', $value);
            }
            if (preg_match('/^\d{8}$/', $value)) {
                return Carbon::createFromFormat('Ymd', $value)->startOfDay();
            }
            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
                return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]);
            }

            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    // --- DJEN ------------------------------------------------------------------

    /** Busca as publicações da OAB desde a última consulta. Retorna quantas são novas. */
    public static function fetchPublications(CourtLawyer $lawyer, ?CourtSetting $s = null): int
    {
        $s ??= CourtSetting::current();
        $to = today();
        $from = $lawyer->synced_until ? $lawyer->synced_until->copy()->subDay() : today()->subDays(self::DJEN_BACKFILL_DAYS);
        if ($from->lt(today()->subDays(30))) {
            $from = today()->subDays(30);
        }

        try {
            $items = app(DjenClient::class)->byOab($lawyer->oab_number, $lawyer->oab_uf, $from, $to);
        } catch (CourtException $e) {
            $lawyer->forceFill(['last_checked_at' => now(), 'last_error' => Str::limit($e->getMessage(), 1000)])->save();
            throw $e;
        }

        $count = 0;
        foreach ($items as $item) {
            if (self::storePublication($item, $lawyer, $s)) {
                $count++;
            }
        }
        $lawyer->forceFill(['last_checked_at' => now(), 'synced_until' => $to, 'last_error' => null])->save();

        return $count;
    }

    /** Grava a comunicação do DJEN (se ainda não existir), vincula ao processo e cria o prazo. */
    public static function storePublication(array $item, ?CourtLawyer $lawyer, CourtSetting $s): ?CourtPublication
    {
        $externalId = (string) ($item['id'] ?? $item['hash'] ?? '');
        $available = self::parseDate($item['data_disponibilizacao'] ?? $item['dataDisponibilizacao'] ?? $item['datadisponibilizacao'] ?? null);
        if ($externalId === '' || ! $available || CourtPublication::where('external_id', Str::limit($externalId, 64, ''))->exists()) {
            return null;
        }

        $digits = Cnj::digits($item['numero_processo'] ?? $item['numeroProcesso'] ?? $item['numeroprocessocommascara'] ?? '');
        $digits = strlen($digits) === 20 ? $digits : null;
        $text = (string) ($item['texto'] ?? '');
        $case = $digits ? self::findCase($digits) : null;
        $days = new BusinessDays($s->holidays);
        $link = (string) ($item['link'] ?? '');

        $publication = CourtPublication::create([
            'external_id' => Str::limit($externalId, 64, ''),
            'court_lawyer_id' => $lawyer?->id,
            'legal_case_id' => $case?->id,
            'process_number' => $digits,
            'court' => Str::limit((string) ($item['siglaTribunal'] ?? ''), 20, '') ?: ($digits ? Cnj::court($digits) : null),
            'kind' => Str::limit((string) ($item['tipoComunicacao'] ?? ''), 60, '') ?: null,
            'document_type' => Str::limit((string) ($item['tipoDocumento'] ?? ''), 100, '') ?: null,
            'organ' => Str::limit((string) ($item['nomeOrgao'] ?? ''), 250, '') ?: null,
            'class_name' => Str::limit((string) ($item['nomeClasse'] ?? ''), 250, '') ?: null,
            'text' => $text,
            'link' => preg_match('#^https?://#i', $link) ? Str::limit($link, 500, '') : null,
            'parties' => collect((array) ($item['destinatarios'] ?? []))->filter(fn ($d) => is_array($d))
                ->map(fn ($d) => ['nome' => $d['nome'] ?? null, 'polo' => $d['polo'] ?? null])->values()->all(),
            'lawyers' => collect((array) ($item['destinatarioadvogados'] ?? []))->filter(fn ($d) => is_array($d))
                ->map(fn ($d) => ['nome' => $d['advogado']['nome'] ?? null, 'oab' => trim(($d['advogado']['numero_oab'] ?? '').'/'.($d['advogado']['uf_oab'] ?? ''), '/')])->values()->all(),
            'available_at' => $available->toDateString(),
            'published_at' => $days->publicationDate($available)->toDateString(),
            'deadline_days' => self::detectDays($text),
            'status' => 'nova',
        ]);

        if ($case) {
            self::recordMovement($publication);
            if ($s->auto_deadline) {
                self::createDeadline($publication);
            }
        }

        Webhooks::dispatch('publicacao.recebida', fn () => self::payload($publication->fresh(['legalCase', 'lawyer', 'task']) ?? $publication));

        return $publication;
    }

    /** Vincula ao processo as publicações novas que chegaram antes de o processo ser cadastrado. */
    public static function linkOrphans(CourtSetting $s): int
    {
        $count = 0;
        CourtPublication::whereNull('legal_case_id')->whereNotNull('process_number')->unread()->limit(200)->get()
            ->each(function (CourtPublication $p) use ($s, &$count) {
                if ($case = self::findCase($p->process_number)) {
                    self::link($p, $case, $s->auto_deadline);
                    $count++;
                }
            });

        return $count;
    }

    public static function link(CourtPublication $p, LegalCase $case, bool $withDeadline = false): void
    {
        $p->forceFill(['legal_case_id' => $case->id])->save();
        $p->setRelation('legalCase', $case);
        self::recordMovement($p);
        if ($withDeadline && ! $p->task_id) {
            self::createDeadline($p);
        }
    }

    public static function findCase(string $digits): ?LegalCase
    {
        $digits = Cnj::digits($digits);
        if (strlen($digits) !== 20) {
            return null;
        }

        return LegalCase::whereIn('number', [$digits, Cnj::mask($digits)])->first()
            ?? LegalCase::where('number', 'like', '%'.substr($digits, 0, 7).'%')->get()
                ->first(fn (LegalCase $c) => Cnj::digits($c->number) === $digits);
    }

    /** Prazo citado no texto ("prazo de 15 (quinze) dias"). */
    public static function detectDays(string $text): ?int
    {
        $plain = self::plain($text);
        if (preg_match('/prazo\s+(?:comum\s+|legal\s+|sucessivo\s+)?de\s+(\d{1,3})\s*(?:\([^)]{1,40}\)\s*)?dias/iu', $plain, $m)) {
            $n = (int) $m[1];

            return $n >= 1 && $n <= 365 ? $n : null;
        }

        return null;
    }

    public static function plain(?string $html): string
    {
        $text = preg_replace('#<\s*(br|/p|/div|/li|/tr)\b[^>]*>#i', "\n", (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    /** Registra a publicação como andamento do processo (sem avisar o cliente pelo WhatsApp). */
    private static function recordMovement(CourtPublication $p): void
    {
        if (! $p->legal_case_id) {
            return;
        }
        $id = 'djen-'.$p->external_id;
        if (CaseMovement::where('legal_case_id', $p->legal_case_id)->where('external_id', Str::limit($id, 64, ''))->exists()) {
            return;
        }
        $wasMuted = Webhooks::$muted;
        Webhooks::$muted = true;
        try {
            CaseMovement::create([
                'legal_case_id' => $p->legal_case_id,
                'date' => $p->available_at->toDateString(),
                'description' => Str::limit('Publicação no DJEN — '.$p->title.($p->organ ? ' ('.$p->organ.')' : '').': '.self::plain($p->text), 3000),
                'source' => 'djen',
                'external_id' => Str::limit($id, 64, ''),
            ]);
        } finally {
            Webhooks::$muted = $wasMuted;
        }
    }

    // --- Prazos ----------------------------------------------------------------

    /** Último dia sugerido para o prazo da publicação. */
    public static function suggestedDue(CourtPublication $p, ?int $days = null, ?CourtSetting $s = null): CarbonImmutable
    {
        $s ??= CourtSetting::current();
        $days ??= $p->deadline_days ?: (int) $s->deadline_days ?: 15;

        return (new BusinessDays($s->holidays))->deadline($p->available_at, $days);
    }

    /**
     * Cria a tarefa de prazo processual da publicação.
     *
     * @param  list<int>|null  $users
     */
    public static function createDeadline(CourtPublication $p, ?int $days = null, ?string $due = null, ?array $users = null, ?User $by = null, ?string $title = null): Task
    {
        if ($p->task_id && ($existing = Task::find($p->task_id))) {
            return $existing;
        }
        $s = CourtSetting::current();
        $days ??= $p->deadline_days ?: (int) $s->deadline_days ?: 15;
        $dueDate = $due ? CarbonImmutable::parse($due) : self::suggestedDue($p, $days, $s);
        $case = $p->legalCase;

        $lines = [
            'Publicação no DJEN'.($p->court ? ' ('.$p->court.')' : '').' disponibilizada em '.$p->available_at->format('d/m/Y')
                .($p->published_at ? ', considerada publicada em '.$p->published_at->format('d/m/Y') : '').'.',
            $due
                ? 'Vencimento informado manualmente.'
                : "Prazo de {$days} dia(s) útil(eis) calculado pelo sistema".($p->deadline_days ? ' (prazo citado no texto)' : '').'. Confira feriados locais e suspensões do tribunal.',
            '',
            Str::limit(self::plain($p->text), 2000),
        ];
        if ($p->link) {
            $lines[] = '';
            $lines[] = 'Documento: '.$p->link;
        }

        $task = Task::create([
            'title' => Str::limit($title ?: 'Prazo: '.($p->kind ?: 'Publicação').' — '.($case?->title ?? $p->masked_number ?? 'DJEN'), 250, ''),
            'description' => implode("\n", $lines),
            'status' => 'pendente',
            'priority' => 'alta',
            'points' => 10,
            'is_deadline' => true,
            'due_at' => $dueDate->setTime(18, 0),
            'legal_case_id' => $case?->id,
            'contact_id' => $case?->contact_id,
            'created_by' => $by?->id,
        ]);

        $assignees = $users ?: array_values(array_filter([$case?->responsible_id ?? $p->lawyer?->user_id]));
        if ($assignees) {
            $task->users()->sync($assignees);
        }
        $p->forceFill(['task_id' => $task->id])->save();

        return $task;
    }

    public static function payload(CourtPublication $p): array
    {
        return [
            'id' => $p->id,
            'process_number' => $p->masked_number,
            'court' => $p->court,
            'kind' => $p->kind,
            'document_type' => $p->document_type,
            'organ' => $p->organ,
            'available_at' => $p->available_at?->toDateString(),
            'published_at' => $p->published_at?->toDateString(),
            'oab' => $p->lawyer?->label,
            'excerpt' => $p->excerpt,
            'link' => $p->link,
            'case' => $p->legalCase ? ['id' => $p->legalCase->id, 'number' => $p->legalCase->number, 'title' => $p->legalCase->title] : null,
            'deadline' => $p->task ? ['task_id' => $p->task->id, 'due_at' => $p->task->due_at?->toDateString()] : null,
            'url' => route('publications.show', $p),
        ];
    }
}
