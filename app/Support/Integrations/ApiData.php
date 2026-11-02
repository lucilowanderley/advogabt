<?php

namespace App\Support\Integrations;

use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Support\Options;

/**
 * Formato JSON único dos registros, usado pela API e pelos webhooks.
 * Campos com o mesmo nome das colunas; datas em ISO 8601; rótulos em pt-BR ao lado dos códigos.
 */
class ApiData
{
    public static function legalCase(LegalCase $c, bool $withMovements = false): array
    {
        $data = [
            'id' => $c->id,
            'number' => $c->number,
            'title' => $c->title,
            'status' => $c->status,
            'status_label' => Options::label(Options::CASE_STATUS, $c->status),
            'phase' => $c->phase,
            'phase_label' => Options::label(Options::CASE_PHASES, $c->phase),
            'area' => $c->area,
            'area_label' => Options::label(Options::CASE_AREAS, $c->area),
            'action_type' => $c->action_type,
            'client_role' => $c->client_role,
            'client' => $c->contact_id ? self::contactRef($c->client) : null,
            'opposing_party' => $c->opposing_party,
            'court' => $c->court,
            'court_division' => $c->court_division,
            'district' => $c->district,
            'claim_value' => $c->claim_value !== null ? (float) $c->claim_value : null,
            'fee_percent' => $c->fee_percent !== null ? (float) $c->fee_percent : null,
            'distributed_at' => $c->distributed_at?->toDateString(),
            'responsible' => $c->responsible ? ['id' => $c->responsible->id, 'name' => $c->responsible->name, 'email' => $c->responsible->email] : null,
            'notes' => $c->notes,
            'url' => route('cases.show', $c),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
        if ($withMovements) {
            $data['movements'] = $c->movements->map(fn (CaseMovement $m) => self::movement($m, false))->all();
        }

        return $data;
    }

    public static function movement(CaseMovement $m, bool $withCase = true): array
    {
        $data = [
            'id' => $m->id,
            'legal_case_id' => $m->legal_case_id,
            'date' => $m->date?->toDateString(),
            'description' => $m->description,
            'author' => $m->user?->name,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
        if ($withCase && $m->legalCase) {
            $case = $m->legalCase;
            $data['case'] = ['id' => $case->id, 'number' => $case->number, 'title' => $case->title, 'client' => $case->contact_id ? self::contactRef($case->client) : null];
        }

        return $data;
    }

    public static function contact(Contact $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'person_type' => $c->person_type,
            'category' => $c->category,
            'category_label' => Options::label(Options::CONTACT_CATEGORIES, $c->category),
            'crm_stage' => $c->crm_stage,
            'crm_stage_label' => Options::label(Options::CRM_STAGES, $c->crm_stage),
            'document' => $c->document,
            'email' => $c->email,
            'phone' => $c->phone,
            'whatsapp' => $c->whatsapp,
            'whatsapp_digits' => self::digits($c->whatsapp ?: $c->phone),
            'birth_date' => $c->birth_date?->toDateString(),
            'city' => $c->city,
            'state' => $c->state,
            'origin' => $c->origin,
            'notes' => $c->notes,
            'url' => route('contacts.show', $c),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function contactRef(?Contact $c): ?array
    {
        return $c ? ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'whatsapp' => $c->whatsapp] : null;
    }

    public static function task(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status,
            'status_label' => Options::label(Options::TASK_STATUS, $t->status),
            'priority' => $t->priority,
            'is_deadline' => (bool) $t->is_deadline,
            'due_at' => $t->due_at?->toIso8601String(),
            'days_left' => $t->due_at ? (int) today()->diffInDays($t->due_at->copy()->startOfDay(), false) : null,
            'points' => $t->points,
            'group' => $t->group,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'case' => $t->legal_case_id && $t->legalCase ? ['id' => $t->legalCase->id, 'number' => $t->legalCase->number, 'title' => $t->legalCase->title] : null,
            'contact' => $t->contact_id ? self::contactRef($t->contact) : null,
            'assignees' => $t->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone])->all(),
            'url' => route('tasks.show', $t),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    public static function event(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'type' => $e->type,
            'type_label' => Options::label(Options::EVENT_TYPES, $e->type),
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'all_day' => (bool) $e->all_day,
            'location' => $e->location,
            'case' => $e->legal_case_id && $e->legalCase ? ['id' => $e->legalCase->id, 'number' => $e->legalCase->number, 'title' => $e->legalCase->title] : null,
            'contact' => $e->contact_id ? self::contactRef($e->contact) : null,
            'responsible' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            'notes' => $e->notes,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    public static function financialEntry(FinancialEntry $f): array
    {
        return [
            'id' => $f->id,
            'type' => $f->type,
            'description' => $f->description,
            'category' => $f->category,
            'amount' => (float) $f->amount,
            'due_date' => $f->due_date?->toDateString(),
            'paid_at' => $f->paid_at?->toDateString(),
            'situation' => $f->situation,
            'payment_method' => $f->payment_method,
            'contact' => $f->contact_id ? self::contactRef($f->contact) : null,
            'case' => $f->legal_case_id && $f->legalCase ? ['id' => $f->legalCase->id, 'number' => $f->legalCase->number, 'title' => $f->legalCase->title] : null,
            'notes' => $f->notes,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }

    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }
}
