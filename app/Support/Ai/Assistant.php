<?php

namespace App\Support\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiProvider;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Support\Format;
use App\Support\Options;
use Illuminate\Support\Str;

/** Monta o contexto do escritório, conversa com o provedor e grava a resposta. */
class Assistant
{
    /** Atalhos exibidos na tela do assistente. 'needs' indica o vínculo exigido. */
    public const ACTIONS = [
        'resumo_processo' => ['needs' => 'case', 'icon' => 'file-text', 'label' => 'Resumir o processo',
            'prompt' => 'Faça um resumo executivo deste processo: objeto, partes, fase atual, últimos andamentos relevantes e pendências.'],
        'proximos_passos' => ['needs' => 'case', 'icon' => 'check-square', 'label' => 'Próximos passos',
            'prompt' => 'Com base nos dados e andamentos, sugira os próximos passos processuais, prazos que merecem atenção e providências do escritório.'],
        'riscos' => ['needs' => 'case', 'icon' => 'alert', 'label' => 'Análise de riscos',
            'prompt' => 'Analise os principais riscos e pontos fortes deste processo para o nosso cliente, indicando o que precisaria ser verificado nos autos.'],
        'explicar_cliente' => ['needs' => 'case', 'icon' => 'whatsapp', 'label' => 'Explicar ao cliente',
            'prompt' => 'Escreva uma mensagem curta de WhatsApp para o cliente explicando, em linguagem simples e sem juridiquês, a situação atual do processo e o que acontece agora.'],
        'peticao' => ['needs' => 'case', 'icon' => 'gavel', 'label' => 'Minuta de petição',
            'prompt' => "Redija uma minuta de petição para este processo com o seguinte objetivo: \n\n(descreva aqui o pedido)"],
        'resumo_cliente' => ['needs' => 'contact', 'icon' => 'user', 'label' => 'Resumo do cliente',
            'prompt' => 'Resuma a situação deste cliente no escritório: processos, pendências e pontos de atenção no relacionamento.'],
        'mensagem_cliente' => ['needs' => 'contact', 'icon' => 'message', 'label' => 'Mensagem ao cliente',
            'prompt' => "Escreva uma mensagem cordial e profissional para este cliente sobre: \n\n(assunto)"],
        'procuracao' => ['needs' => null, 'icon' => 'file-text', 'label' => 'Procuração ad judicia',
            'prompt' => 'Redija uma procuração ad judicia et extra com poderes gerais e especiais usuais, deixando campos entre colchetes para os dados que faltarem.'],
        'fundamentacao' => ['needs' => null, 'icon' => 'scale', 'label' => 'Fundamentação legal',
            'prompt' => "Indique a fundamentação legal (legislação brasileira) aplicável à seguinte situação, citando os dispositivos: \n\n(descreva o caso)"],
        'revisar' => ['needs' => null, 'icon' => 'edit', 'label' => 'Revisar texto',
            'prompt' => "Revise o texto abaixo quanto à clareza, coesão, gramática e técnica jurídica, apontando as alterações:\n\n(cole o texto)"],
        'prazo' => ['needs' => null, 'icon' => 'clock', 'label' => 'Calcular prazo',
            'prompt' => "Explique como contar o prazo processual a seguir (CPC, dias úteis) e qual seria a data final: \n\n(ato, data da intimação e tribunal)"],
    ];

    public function __construct(private LlmClient $client) {}

    public static function actionsFor(?AiConversation $conversation): array
    {
        return array_filter(self::ACTIONS, fn ($a) => $a['needs'] === null
            || ($a['needs'] === 'case' && $conversation?->legal_case_id)
            || ($a['needs'] === 'contact' && $conversation?->contact_id));
    }

    /** Envia o histórico ao provedor e grava a resposta (ou o erro) na conversa. */
    public function reply(AiConversation $conversation, ?AiProvider $provider = null): AiMessage
    {
        $provider ??= $conversation->provider?->active ? $conversation->provider : AiProvider::default();
        if (! $provider) {
            throw new AiException('Nenhum provedor de IA está configurado. Um administrador precisa cadastrar um em Assistente IA › Provedores.');
        }

        $system = $this->systemPrompt($conversation, $provider);

        try {
            $reply = $this->client->chat($provider, $system, $this->history($conversation));
        } catch (AiException $e) {
            return $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $e->getMessage(),
                'provider_name' => $provider->name,
                'model' => $provider->model,
                'is_error' => true,
            ]);
        }

        $conversation->forceFill(['ai_provider_id' => $provider->id])->touch();

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply->text,
            'provider_name' => $provider->name,
            'model' => $reply->model,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
        ]);
    }

    /**
     * Histórico no formato comum: começa pelo usuário, alterna papéis e ignora respostas com erro.
     */
    public function history(AiConversation $conversation): array
    {
        $limit = max(2, (int) config('services.ai.history', 20));
        $rows = $conversation->messages()->where('is_error', false)->get()->slice(-$limit);

        $out = [];
        foreach ($rows as $m) {
            $role = $m->role === 'assistant' ? 'assistant' : 'user';
            if ($out === [] && $role === 'assistant') {
                continue;
            }
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['content'] .= "\n\n".$m->content;
            } else {
                $out[] = ['role' => $role, 'content' => $m->content];
            }
        }

        return $out;
    }

    public function systemPrompt(AiConversation $conversation, AiProvider $provider): string
    {
        $user = $conversation->user;
        $parts = [
            'Você é o assistente jurídico do escritório '.config('app.name').', integrado ao sistema de gestão ADVOGABT. '
            .'Trabalha para advogados(as) brasileiros(as) e responde sempre em português do Brasil.',
            'Diretrizes:'
            ."\n- Baseie-se na legislação e na prática forense brasileiras (CF, CPC, CC, CLT, CDC etc.)."
            ."\n- Nunca invente jurisprudência, números de processos, súmulas ou dispositivos legais. Se não tiver certeza, diga isso claramente e indique o que deve ser conferido."
            ."\n- Use apenas os dados do escritório fornecidos abaixo; quando faltar uma informação, deixe um campo entre colchetes, ex.: [data da audiência]."
            ."\n- Peças e minutas devem vir completas e prontas para revisão, em linguagem técnica adequada. Mensagens a clientes devem ser simples e cordiais."
            ."\n- Formate com Markdown (títulos, listas, negrito) quando ajudar a leitura."
            ."\n- O trabalho é sempre revisado pelo(a) advogado(a) responsável antes de qualquer uso.",
            'Data de hoje: '.now()->translatedFormat('l, d \d\e F \d\e Y').'.',
            'Usuário: '.$user?->name.($user?->oab ? ' (OAB '.$user->oab.')' : '').' · perfil '.$user?->role_label.'.',
        ];

        if ($provider->system_prompt) {
            $parts[] = "Instruções do escritório:\n".$provider->system_prompt;
        }
        if ($conversation->legalCase) {
            $parts[] = $this->caseContext($conversation->legalCase);
        }
        if ($conversation->contact) {
            $parts[] = $this->contactContext($conversation->contact);
        }

        return implode("\n\n", $parts);
    }

    public function caseContext(LegalCase $case): string
    {
        $case->loadMissing(['client', 'responsible', 'partner']);
        $lines = ['## Processo vinculado a esta conversa'];
        $lines[] = $this->fields([
            'Título' => $case->title,
            'Número' => $case->number,
            'Cliente' => $case->client?->name,
            'Polo do cliente' => Options::label(Options::CLIENT_ROLES, $case->client_role),
            'Parte contrária' => $case->opposing_party,
            'Área' => Options::label(Options::CASE_AREAS, $case->area),
            'Tipo de ação' => $case->action_type,
            'Tribunal' => $case->court,
            'Vara' => $case->court_division,
            'Comarca' => $case->district,
            'Fase' => Options::label(Options::CASE_PHASES, $case->phase),
            'Situação' => Options::label(Options::CASE_STATUS, $case->status),
            'Valor da causa' => $case->claim_value !== null ? Format::money($case->claim_value) : null,
            'Distribuído em' => $case->distributed_at?->format('d/m/Y'),
            'Responsável' => $case->responsible?->name,
            'Parceiro' => $case->partner?->name,
            'Observações' => $case->notes,
        ]);

        $movements = $case->movements()->limit(30)->get();
        if ($movements->isNotEmpty()) {
            $lines[] = "\nAndamentos (mais recentes primeiro):";
            foreach ($movements as $m) {
                $lines[] = '- '.$m->date->format('d/m/Y').': '.Str::limit(preg_replace('/\s+/', ' ', $m->description), 600);
            }
        }

        $tasks = $case->tasks()->where('status', '!=', 'concluida')->orderBy('due_at')->limit(15)->get();
        if ($tasks->isNotEmpty()) {
            $lines[] = "\nTarefas e prazos em aberto:";
            foreach ($tasks as $t) {
                $lines[] = '- '.$t->title.($t->due_at ? ' (até '.$t->due_at->format('d/m/Y').')' : '').($t->is_deadline ? ' [prazo processual]' : '');
            }
        }

        $events = $case->events()->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->limit(10)->get();
        if ($events->isNotEmpty()) {
            $lines[] = "\nPróximos compromissos:";
            foreach ($events as $e) {
                $lines[] = '- '.$e->starts_at->format('d/m/Y H:i').' · '.Options::label(Options::EVENT_TYPES, $e->type).': '.$e->title.($e->location ? ' ('.$e->location.')' : '');
            }
        }

        if ($case->client) {
            $lines[] = "\n".$this->contactContext($case->client, '### Qualificação do cliente', false);
        }

        return implode("\n", $lines);
    }

    public function contactContext(Contact $contact, string $title = '## Contato vinculado a esta conversa', bool $withCases = true): string
    {
        $address = collect([$contact->address, $contact->city, $contact->state, $contact->zip_code ? 'CEP '.$contact->zip_code : null])->filter()->implode(', ');
        $lines = [$title, $this->fields([
            'Nome' => $contact->name,
            'Tipo' => $contact->person_type === 'PJ' ? 'Pessoa jurídica' : 'Pessoa física',
            'Categoria' => Options::label(Options::CONTACT_CATEGORIES, $contact->category),
            'Etapa no CRM' => Options::label(Options::CRM_STAGES, $contact->crm_stage),
            'CPF/CNPJ' => $contact->document,
            'RG' => $contact->rg,
            'Nacionalidade' => $contact->nationality,
            'Estado civil' => $contact->marital_status,
            'Profissão' => $contact->profession,
            'Nascimento' => $contact->birth_date?->format('d/m/Y'),
            'Endereço' => $address,
            'E-mail' => $contact->email,
            'Telefone' => $contact->phone ?: $contact->whatsapp,
            'Observações' => $contact->notes,
        ])];

        if ($withCases) {
            $cases = $contact->legalCases()->latest()->limit(15)->get();
            if ($cases->isNotEmpty()) {
                $lines[] = "\nProcessos do contato:";
                foreach ($cases as $c) {
                    $lines[] = '- '.$c->display_name.' · '.Options::label(Options::CASE_PHASES, $c->phase).' · '.Options::label(Options::CASE_STATUS, $c->status);
                }
            }
            $tasks = $contact->tasks()->where('status', '!=', 'concluida')->orderBy('due_at')->limit(10)->get();
            if ($tasks->isNotEmpty()) {
                $lines[] = "\nTarefas em aberto:";
                foreach ($tasks as $t) {
                    $lines[] = '- '.$t->title.($t->due_at ? ' (até '.$t->due_at->format('d/m/Y').')' : '');
                }
            }
        }

        return implode("\n", $lines);
    }

    private function fields(array $fields): string
    {
        return collect($fields)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== '—')
            ->map(fn ($v, $k) => '- '.$k.': '.$v)
            ->implode("\n");
    }

    public static function titleFrom(string $text): string
    {
        $title = Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 70);

        return $title !== '' ? $title : 'Nova conversa';
    }
}
