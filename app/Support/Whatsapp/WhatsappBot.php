<?php

namespace App\Support\Whatsapp;

use App\Models\AiProvider;
use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Support\Ai\AiException;
use App\Support\Ai\LlmClient;
use App\Support\Format;
use App\Support\Integrations\Webhooks;
use App\Support\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Autoatendimento pelo WhatsApp: identifica o cliente pelo número, responde ao
 * menu, informa o andamento dos processos com o Assistente IA, registra pedidos
 * de agendamento e documentos e passa a conversa para a equipe quando pedido.
 */
class WhatsappBot
{
    private const GREETINGS = ['oi', 'ola', 'olá', 'oie', 'bom dia', 'boa tarde', 'boa noite', 'menu', 'inicio', 'início', 'opcoes', 'opções', 'ajuda', 'voltar', '0'];

    public function __construct(private WhatsappClient $client, private LlmClient $llm) {}

    // --- Entrada ---------------------------------------------------------------

    /** Trata uma mensagem recebida. Devolve a mensagem gravada (ou null se repetida). */
    public function handle(WhatsappChannel $channel, Inbound $in): ?WhatsappMessage
    {
        if ($in->phone === '' || WhatsappMessage::where('external_id', $in->externalId)->whereHas('conversation', fn ($q) => $q->where('whatsapp_channel_id', $channel->id))->exists()) {
            return null; // o serviço reenviou o mesmo aviso
        }

        $conversation = WhatsappConversation::firstOrNew(['whatsapp_channel_id' => $channel->id, 'phone' => $in->phone], ['mode' => 'bot', 'unread' => 0]);
        $isNew = ! $conversation->exists;
        // Atendimento humano parado há mais de 24h volta para o robô.
        if ($conversation->mode === 'humano' && $conversation->last_message_at?->lt(now()->subDay())) {
            $conversation->mode = 'bot';
            $conversation->state = null;
        }
        $conversation->fill([
            'profile_name' => $in->profileName ?: $conversation->profile_name,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $conversation->contact_id ??= Contact::findByPhone($in->phone)?->id;
        $conversation->unread = ($conversation->unread ?? 0) + 1;
        $conversation->save();
        $channel->forceFill(['last_inbound_at' => now()])->saveQuietly();

        $message = $conversation->messages()->create([
            'direction' => 'in',
            'sender' => 'cliente',
            'type' => $in->type,
            'body' => $in->text,
            'media_name' => $in->mediaName,
            'media_mime' => $in->mediaMime,
            'external_id' => $in->externalId,
            'status' => 'ok',
        ]);
        if ($in->hasMedia()) {
            $this->storeMedia($channel, $conversation, $message, $in);
        }

        Webhooks::dispatch('whatsapp.mensagem_recebida', fn () => $this->eventData($conversation, $message));

        if ($channel->bot_enabled && $conversation->mode === 'bot') {
            try {
                $this->respond($channel, $conversation->load('contact'), $message, $isNew);
            } catch (\Throwable $e) {
                Log::warning('Robô do WhatsApp falhou: '.$e->getMessage());
            }
        }

        return $message;
    }

    private function respond(WhatsappChannel $channel, WhatsappConversation $c, WhatsappMessage $m, bool $isNew): void
    {
        $text = trim((string) $m->body);
        $norm = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9 ]/', '')->squish()->toString();

        if (in_array($m->type, ['document', 'image', 'video'], true)) {
            $this->teamTask($channel, $c, 'Documento recebido pelo WhatsApp', 'O cliente enviou arquivo(s) pelo WhatsApp. Confira na conversa e anexe ao processo.');
            $this->reply($c, 'Recebemos o seu arquivo'.($m->media_name ? " ({$m->media_name})" : '').'. A equipe vai analisar e, se precisar de mais alguma coisa, entra em contato. Pode enviar outros, se houver.');

            return;
        }
        if ($m->type === 'audio') {
            $this->reply($c, 'Ainda não consigo ouvir áudios. Pode escrever a sua dúvida? Se preferir falar com a equipe, responda 4.');

            return;
        }
        if ($text === '') {
            return;
        }

        // Etapas que aguardam uma resposta do cliente
        if ($c->state === 'agendar' && ! $this->isMenuChoice($norm)) {
            $this->teamTask($channel, $c, 'Agendar atendimento (WhatsApp)', "Pedido do cliente:\n{$text}", now()->addDay());
            $this->setState($c, null);
            $this->reply($c, 'Pedido de agendamento registrado. A equipe vai confirmar o dia e o horário por aqui. Obrigado!');

            return;
        }
        if ($c->state === 'identificacao' && ! $this->isMenuChoice($norm)) {
            $this->teamTask($channel, $c, 'Identificar cliente no WhatsApp', "O número {$c->formatted_phone} não está cadastrado. O cliente informou:\n{$text}\n\nCadastre o telefone no contato para o robô reconhecê-lo.");
            $this->setState($c, null);
            $this->reply($c, 'Obrigado! A equipe vai conferir o seu cadastro e retornar por aqui.');

            return;
        }
        if ($c->state === 'escolher_processo' && preg_match('/^\d{1,2}$/', $norm)) {
            $case = $this->clientCases($c)->values()->get((int) $norm - 1);
            if ($case) {
                $this->setState($c, null);
                $this->reply($c, $this->caseAnswer($channel, $c, $case, 'Qual é a situação atual deste processo?'));

                return;
            }
        }

        $choice = $this->menuChoice($norm);
        if ($choice === 'menu' || ($isNew && $choice === null && str_word_count($norm) <= 4)) {
            $this->setState($c, null);
            $this->reply($c, $this->greeting($channel, $c));

            return;
        }

        match ($choice) {
            'processo' => $this->askCases($channel, $c, $text),
            'agendar' => $this->startScheduling($c),
            'documentos' => $this->reply($c, 'Pode enviar os documentos aqui mesmo, em PDF ou foto bem legível. Avisamos assim que recebermos.'),
            'humano' => $this->handoff($channel, $c, $text),
            default => $this->reply($c, $this->freeAnswer($channel, $c, $isNew)),
        };
    }

    private function isMenuChoice(string $norm): bool
    {
        return in_array($norm, ['1', '2', '3', '4', '0', 'menu'], true);
    }

    private function menuChoice(string $norm): ?string
    {
        return match (true) {
            in_array($norm, self::GREETINGS, true), (bool) preg_match('/^(oi|ola|bom dia|boa tarde|boa noite)\b/', $norm) && str_word_count($norm) <= 3 => 'menu',
            $norm === '1' => 'processo',
            $norm === '2' => 'agendar',
            $norm === '3' => 'documentos',
            $norm === '4', (bool) preg_match('/\b(atendente|humano|advogad[oa]|falar com (alguem|a equipe|voces))\b/', $norm) => 'humano',
            (bool) preg_match('/\b(agendar|agendamento|marcar (uma )?(reuniao|horario|consulta|atendimento))\b/', $norm) => 'agendar',
            default => null,
        };
    }

    private function greeting(WhatsappChannel $channel, WhatsappConversation $c): string
    {
        $name = $c->contact ? Str::of($c->contact->name)->explode(' ')->first() : null;
        $text = $channel->greetingText();

        return $name && ! str_contains(Str::lower($text), Str::lower($name)) ? preg_replace('/^(Olá|Oi)!?/u', "Olá, {$name}!", $text, 1) : $text;
    }

    private function askCases(WhatsappChannel $channel, WhatsappConversation $c, string $question): void
    {
        if (! $c->contact) {
            $this->setState($c, 'identificacao');
            $this->reply($c, 'Não encontrei o seu cadastro por este número. Por segurança, informe o seu nome completo e CPF (ou o número do processo) que a equipe confere e retorna.');

            return;
        }
        $cases = $this->clientCases($c);
        if ($cases->isEmpty()) {
            $this->reply($c, 'Não encontrei processos em andamento no seu nome. Se quiser falar com a equipe, responda 4.');

            return;
        }
        if ($cases->count() === 1) {
            $this->reply($c, $this->caseAnswer($channel, $c, $cases->first(), preg_match('/^\d$/', trim($question)) ? 'Qual é a situação atual do meu processo?' : $question));

            return;
        }
        $this->setState($c, 'escolher_processo');
        $lines = $cases->values()->map(fn (LegalCase $k, $i) => ($i + 1).' - '.($k->number ? "{$k->number} · " : '').Str::limit($k->title, 60));
        $this->reply($c, "Encontrei {$cases->count()} processos. Qual deles?\n\n".$lines->implode("\n")."\n\nResponda com o número.");
    }

    private function startScheduling(WhatsappConversation $c): void
    {
        $this->setState($c, 'agendar');
        $this->reply($c, 'Claro! Qual o assunto e quais dias e horários são melhores para você? Atendimento presencial ou online?');
    }

    private function handoff(WhatsappChannel $channel, WhatsappConversation $c, string $text): void
    {
        $c->forceFill(['mode' => 'humano', 'state' => null])->save();
        $this->teamTask($channel, $c, 'Responder cliente no WhatsApp', "O cliente pediu para falar com a equipe.\nÚltima mensagem: {$text}", now(), 'alta');
        Webhooks::dispatch('whatsapp.atendimento_humano', fn () => $this->eventData($c, $c->messages()->latest('id')->first()));
        $this->reply($c, 'Certo, vou chamar alguém da equipe para continuar o atendimento por aqui. Aguarde um momento, por favor.');
    }

    // --- Respostas com a IA -----------------------------------------------------

    private function freeAnswer(WhatsappChannel $channel, WhatsappConversation $c, bool $isNew = false): string
    {
        return $this->ai($channel, $c, $this->clientContext($c))
            ?? ($isNew ? $this->greeting($channel, $c) : null)
            ?? "Não entendi muito bem. Escolha uma opção:\n\n1 - Andamento do meu processo\n2 - Agendar um atendimento\n3 - Enviar documentos\n4 - Falar com a equipe";
    }

    private function caseAnswer(WhatsappChannel $channel, WhatsappConversation $c, LegalCase $case, string $question): string
    {
        $context = $this->clientContext($c, $case);
        $answer = $this->ai($channel, $c, $context, $question);
        if ($answer) {
            return $answer;
        }
        $last = $case->movements()->first();

        return 'Processo '.($case->number ?: $case->title).': situação '.Str::lower(Options::label(Options::CASE_STATUS, $case->status))
            .', fase '.Str::lower(Options::label(Options::CASE_PHASES, $case->phase)).'.'
            .($last ? "\nÚltima movimentação em {$last->date->format('d/m/Y')}: ".Str::limit($last->description, 300) : '')
            ."\n\nPara mais detalhes, responda 4 e falamos com você.";
    }

    /** Chama a IA com o histórico da conversa. Devolve null quando não há IA ou ela falha. */
    private function ai(WhatsappChannel $channel, WhatsappConversation $c, string $context, ?string $question = null): ?string
    {
        $provider = $channel->aiProvider?->active ? $channel->aiProvider : AiProvider::default();
        if (! $provider) {
            return null;
        }
        $messages = $this->history($c);
        if ($question !== null) {
            $messages[] = ['role' => 'user', 'content' => $question];
            $messages = $this->mergeRoles($messages);
        }
        if ($messages === []) {
            return null;
        }

        try {
            $reply = $this->llm->chat($provider, $this->systemPrompt($channel, $context), $messages, 700);
            $text = trim(preg_replace(['/\*\*(.+?)\*\*/s', '/^#+\s*/m'], ['*$1*', ''], $reply->text)); // Markdown → WhatsApp

            return $text !== '' ? $text : null;
        } catch (AiException $e) {
            Log::warning('IA do WhatsApp falhou: '.$e->getMessage());

            return null;
        }
    }

    private function systemPrompt(WhatsappChannel $channel, string $context): string
    {
        $parts = [
            'Você é o atendente virtual do escritório de advocacia '.config('app.name').' no WhatsApp, falando com clientes e interessados. Responda sempre em português do Brasil.',
            'Regras:'
            ."\n- Mensagens curtas (até 6 linhas), cordiais e em linguagem simples, sem juridiquês. Explique termos técnicos com palavras do dia a dia."
            ."\n- Use apenas as informações dos dados abaixo. Nunca invente andamentos, datas, valores ou resultados."
            ."\n- Não dê parecer jurídico, não preveja resultado e não prometa prazos. Para isso, ofereça falar com a equipe (opção 4)."
            ."\n- Não revele dados de outras pessoas nem informações internas do escritório."
            ."\n- Se a pessoa quiser agendar, diga para responder 2; para enviar documentos, 3; para falar com a equipe, 4."
            ."\n- Formatação do WhatsApp: *negrito* com um asterisco, sem títulos nem tabelas.",
            'Data de hoje: '.now()->translatedFormat('d/m/Y (l)').'.',
        ];
        if ($channel->instructions) {
            $parts[] = "Orientações do escritório:\n".$channel->instructions;
        }
        $parts[] = $context;

        return implode("\n\n", $parts);
    }

    /** Dados que o cliente pode ver: seus processos, últimos andamentos, audiências e valores em aberto. */
    private function clientContext(WhatsappConversation $c, ?LegalCase $only = null): string
    {
        $contact = $c->contact;
        if (! $contact) {
            return "## Quem está falando\nNúmero não cadastrado no escritório ({$c->formatted_phone}). Não informe nada sobre processos; para isso a pessoa precisa ser identificada pela equipe (opção 1 pede nome e CPF).";
        }

        $lines = ["## Cliente\nNome: {$contact->name}"];
        $cases = $only ? collect([$only]) : $this->clientCases($c);
        foreach ($cases as $case) {
            $lines[] = '## Processo '.($case->number ?: '(sem número)')." — {$case->title}"
                ."\nSituação: ".Options::label(Options::CASE_STATUS, $case->status).' · Fase: '.Options::label(Options::CASE_PHASES, $case->phase)
                .($case->court ? "\nTribunal/vara: ".trim($case->court.' '.$case->court_division) : '')
                .($case->opposing_party ? "\nParte contrária: {$case->opposing_party}" : '')
                .($case->responsible ? "\nAdvogado(a) responsável: {$case->responsible->name}" : '');
            $movements = $case->movements()->limit(5)->get();
            if ($movements->isNotEmpty()) {
                $lines[] = "Últimos andamentos:\n".$movements->map(fn (CaseMovement $m) => '- '.$m->date->format('d/m/Y').': '.Str::limit($m->description, 400))->implode("\n");
            }
        }
        $events = Event::query()
            ->where('starts_at', '>=', now())
            ->where(fn ($q) => $q->where('contact_id', $contact->id)->orWhereIn('legal_case_id', $cases->pluck('id')))
            ->orderBy('starts_at')->limit(5)->get();
        if ($events->isNotEmpty()) {
            $lines[] = "## Próximos compromissos\n".$events->map(fn (Event $e) => '- '.$e->starts_at->format($e->all_day ? 'd/m/Y' : 'd/m/Y H:i').' · '.Options::label(Options::EVENT_TYPES, $e->type).": {$e->title}".($e->location ? " ({$e->location})" : ''))->implode("\n");
        }
        $open = FinancialEntry::where('contact_id', $contact->id)->where('type', 'receita')->unpaid()->orderBy('due_date')->limit(5)->get();
        if ($open->isNotEmpty()) {
            $lines[] = "## Valores em aberto do cliente\n".$open->map(fn (FinancialEntry $f) => "- {$f->description}: ".Format::money($f->amount).' com vencimento em '.$f->due_date->format('d/m/Y'))->implode("\n");
        }

        return implode("\n\n", $lines);
    }

    private function clientCases(WhatsappConversation $c)
    {
        if (! $c->contact_id) {
            return collect();
        }

        return LegalCase::with('responsible')->where('contact_id', $c->contact_id)
            ->whereNotIn('status', ['arquivado', 'encerrado'])->orderByDesc('updated_at')->limit(9)->get();
    }

    private function history(WhatsappConversation $c): array
    {
        $rows = $c->messages()->whereNotNull('body')->where('status', 'ok')->latest('id')->limit(12)->get()->reverse();

        return $this->mergeRoles($rows->map(fn (WhatsappMessage $m) => ['role' => $m->isInbound() ? 'user' : 'assistant', 'content' => (string) $m->body])->values()->all());
    }

    /** As APIs exigem papéis alternados, começando pelo usuário. */
    private function mergeRoles(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($out === [] && $m['role'] === 'assistant') {
                continue;
            }
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $m['role']) {
                $out[$last]['content'] .= "\n".$m['content'];
            } else {
                $out[] = $m;
            }
        }

        return $out;
    }

    // --- Saída -----------------------------------------------------------------

    /** Envia uma mensagem do robô ou da equipe e grava no histórico. */
    public function send(WhatsappConversation $c, string $text, string $sender = 'bot', ?User $user = null): WhatsappMessage
    {
        $message = $c->messages()->create(['direction' => 'out', 'sender' => $sender, 'user_id' => $user?->id, 'type' => 'text', 'body' => $text, 'status' => 'ok']);
        try {
            $id = $this->client->sendText($c->channel, $c->phone, $text);
            $message->forceFill(['external_id' => $id])->save();
        } catch (WhatsappException $e) {
            $message->forceFill(['status' => 'erro', 'error' => $e->getMessage()])->save();
        }
        $c->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    private function reply(WhatsappConversation $c, string $text): void
    {
        $this->send($c, $text);
    }

    private function setState(WhatsappConversation $c, ?string $state): void
    {
        $c->forceFill(['state' => $state])->save();
    }

    /** Cria (uma vez por dia e por assunto) uma tarefa para a equipe atender a conversa. */
    private function teamTask(WhatsappChannel $channel, WhatsappConversation $c, string $subject, string $description, $due = null, string $priority = 'normal'): void
    {
        $title = "{$subject}: {$c->display_name}";
        if (Task::open()->where('title', $title)->where('created_at', '>=', today())->exists()) {
            return;
        }
        $task = Task::create([
            'title' => $title,
            'description' => $description."\n\nConversa: ".route('whatsapp.show', $c),
            'priority' => $priority,
            'group' => 'WhatsApp',
            'due_at' => $due ?? now()->endOfDay(),
            'contact_id' => $c->contact_id,
            'points' => 5,
        ]);
        $task->users()->sync($channel->teamIds());
    }

    private function storeMedia(WhatsappChannel $channel, WhatsappConversation $c, WhatsappMessage $m, Inbound $in): void
    {
        try {
            [$content, $mime] = $this->client->downloadMedia($channel, $in);
            $ext = $this->extension($in->mediaName, $mime);
            $path = "whatsapp/{$c->id}/".now()->format('Ymd-His').'-'.Str::random(6).($ext ? ".{$ext}" : '');
            Storage::disk('local')->put($path, $content);
            $m->forceFill(['media_path' => $path, 'media_mime' => $mime ?: $m->media_mime, 'media_name' => $m->media_name ?: basename($path)])->save();
        } catch (\Throwable $e) {
            $m->forceFill(['error' => 'Arquivo não baixado: '.Str::limit($e->getMessage(), 300)])->save();
        }
    }

    private function extension(?string $name, ?string $mime): ?string
    {
        $ext = $name ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,5}$/', $ext)) {
            return $ext;
        }

        return match (strtok((string) $mime, ';')) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            default => null,
        };
    }

    public function eventData(WhatsappConversation $c, ?WhatsappMessage $m): array
    {
        return [
            'conversation_id' => $c->id,
            'phone' => $c->phone,
            'profile_name' => $c->profile_name,
            'mode' => $c->mode,
            'contact' => $c->contact ? ['id' => $c->contact->id, 'name' => $c->contact->name] : null,
            'message' => $m ? ['id' => $m->id, 'type' => $m->type, 'body' => $m->body, 'media_name' => $m->media_name, 'created_at' => $m->created_at?->toIso8601String()] : null,
            'url' => route('whatsapp.show', $c),
        ];
    }

    // --- Aviso de novo andamento -----------------------------------------------

    /** Avisa o cliente (se o canal permitir) quando um andamento é registrado no processo dele. */
    public function notifyMovement(CaseMovement $movement): ?WhatsappMessage
    {
        $channel = WhatsappChannel::active()->where('notify_movements', true)->orderBy('id')->first();
        $case = $movement->legalCase;
        $contact = $case?->client;
        $phone = preg_replace('/\D/', '', (string) ($contact?->whatsapp ?: $contact?->phone));
        if (! $channel || ! $contact || strlen($phone) < 10) {
            return null;
        }
        if (strlen($phone) <= 11) {
            $phone = '55'.$phone;
        }
        $c = WhatsappConversation::firstOrCreate(['whatsapp_channel_id' => $channel->id, 'phone' => $phone], ['contact_id' => $contact->id, 'mode' => 'bot', 'last_message_at' => now()]);
        $c->setRelation('contact', $contact);

        $first = Str::of($contact->name)->explode(' ')->first();
        $fallback = "Olá, {$first}! Há uma novidade no seu processo ".($case->number ?: $case->title).' ('.$movement->date->format('d/m/Y').'): '
            .Str::limit($movement->description, 300)."\n\nSe tiver dúvidas, é só responder esta mensagem.";
        $text = $this->ai($channel, $c, $this->clientContext($c, $case),
            "Escreva uma mensagem curta de WhatsApp para {$first} avisando, em linguagem simples, sobre este novo andamento do processo e o que ele significa, sem prever resultado: \"{$movement->description}\". Termine dizendo que pode responder esta mensagem em caso de dúvida.") ?? $fallback;

        return $this->send($c, $text, 'sistema');
    }
}
