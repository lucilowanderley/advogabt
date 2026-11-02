<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Support\Whatsapp\WhatsappBot;
use App\Support\Whatsapp\WhatsappClient;
use App\Support\Whatsapp\WhatsappException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Caixa de entrada do WhatsApp (equipe) e cadastro dos canais (administradores). */
class WhatsappController extends Controller
{
    public const FILTERS = ['todas' => 'Todas', 'equipe' => 'Aguardando a equipe', 'nao_lidas' => 'Não lidas', 'robo' => 'Com o robô'];

    public function index(Request $request, ?WhatsappConversation $conversation = null): View
    {
        if ($conversation?->unread) {
            $conversation->forceFill(['unread' => 0])->saveQuietly();
        }
        $filter = array_key_exists((string) $request->query('filtro'), self::FILTERS) ? $request->query('filtro') : 'todas';
        $conversations = WhatsappConversation::with(['contact', 'lastMessage', 'assignee'])
            ->when($filter === 'equipe', fn ($q) => $q->where('mode', 'humano'))
            ->when($filter === 'nao_lidas', fn ($q) => $q->where('unread', '>', 0))
            ->when($filter === 'robo', fn ($q) => $q->where('mode', 'bot'))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')
                ->orWhere('profile_name', 'like', "%{$term}%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->orderByDesc('last_message_at')
            ->paginate(30)->withQueryString();

        if ($conversation) {
            $conversation->load(['contact', 'channel', 'assignee', 'messages.user']);
        }

        return view('whatsapp.index', [
            'conversations' => $conversations,
            'conversation' => $conversation,
            'filter' => $filter,
            'channels' => WhatsappChannel::count(),
            'waiting' => WhatsappConversation::where('mode', 'humano')->count(),
            'contacts' => $conversation && ! $conversation->contact_id ? Contact::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function show(Request $request, WhatsappConversation $conversation): View
    {
        return $this->index($request, $conversation);
    }

    /** Mensagens novas (atualização automática da conversa aberta). */
    public function poll(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $after = (int) $request->query('after');
        $messages = $conversation->messages()->with('user')->where('id', '>', $after)->get();
        if ($messages->isNotEmpty()) {
            $conversation->forceFill(['unread' => 0])->saveQuietly();
        }

        return response()->json([
            'last_id' => $messages->last()?->id ?? $after,
            'html' => $messages->map(fn ($m) => view('whatsapp._message', ['m' => $m])->render())->implode(''),
            'mode' => $conversation->fresh()->mode,
        ]);
    }

    public function send(Request $request, WhatsappConversation $conversation, WhatsappBot $bot): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'mensagem']);
        $warning = $conversation->channel->driver === 'meta' && ! $conversation->insideServiceWindow()
            ? ' Atenção: passaram 24h desde a última mensagem do cliente; a API oficial pode recusar texto livre.' : '';

        // A equipe assumiu: o robô para de responder nesta conversa.
        $conversation->forceFill(['mode' => 'humano', 'assigned_user_id' => $conversation->assigned_user_id ?? $request->user()->id, 'state' => null])->save();
        $message = $bot->send($conversation, $data['body'], 'equipe', $request->user());

        return redirect()->route('whatsapp.show', $conversation)->with(
            $message->status === 'ok' ? 'success' : 'error',
            $message->status === 'ok' ? 'Mensagem enviada.'.$warning : 'A mensagem não foi enviada: '.$message->error
        );
    }

    public function mode(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $mode = $request->validate(['mode' => ['required', Rule::in(['bot', 'humano'])]])['mode'];
        $conversation->forceFill([
            'mode' => $mode,
            'state' => null,
            'assigned_user_id' => $mode === 'humano' ? $request->user()->id : null,
        ])->save();

        return back()->with('success', $mode === 'bot' ? 'Conversa devolvida ao robô.' : 'Você assumiu a conversa. O robô não responde até ela voltar para ele.');
    }

    public function linkContact(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'exists:contacts,id'], 'save_phone' => ['nullable', 'boolean']]);
        $conversation->forceFill(['contact_id' => $data['contact_id'] ?? null])->save();
        if (($data['contact_id'] ?? null) && $request->boolean('save_phone')) {
            $contact = Contact::find($data['contact_id']);
            if (! $contact->whatsapp) {
                $contact->update(['whatsapp' => $conversation->formatted_phone]);
            }
        }

        return back()->with('success', 'Contato vinculado à conversa.');
    }

    public function media(WhatsappMessage $message): StreamedResponse
    {
        abort_unless($message->media_path && Storage::disk('local')->exists($message->media_path), 404);

        return Storage::disk('local')->download($message->media_path, $message->media_name ?: basename($message->media_path));
    }

    // --- Canais (administradores) -------------------------------------------

    public function channels(): View
    {
        return view('whatsapp.channels.index', ['channels' => WhatsappChannel::withCount('conversations')->orderBy('name')->get()]);
    }

    public function createChannel(Request $request): View
    {
        $driver = array_key_exists((string) $request->query('tipo'), WhatsappChannel::DRIVERS) ? $request->query('tipo') : 'evolution';

        return view('whatsapp.channels.form', $this->formData(new WhatsappChannel([
            'driver' => $driver,
            'name' => 'WhatsApp do escritório',
            'active' => true,
            'bot_enabled' => true,
        ])));
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $channel = WhatsappChannel::create($this->validatedChannel($request));

        return redirect()->route('whatsapp.channels.edit', $channel)->with('success', 'Canal cadastrado. Agora configure a URL de recebimento no serviço de WhatsApp (veja abaixo) e use "Testar conexão".');
    }

    public function editChannel(WhatsappChannel $channel): View
    {
        return view('whatsapp.channels.form', $this->formData($channel));
    }

    public function updateChannel(Request $request, WhatsappChannel $channel): RedirectResponse
    {
        $channel->update($this->validatedChannel($request, $channel));

        return redirect()->route('whatsapp.channels.edit', $channel)->with('success', 'Canal atualizado.');
    }

    public function destroyChannel(WhatsappChannel $channel): RedirectResponse
    {
        $channel->delete();

        return redirect()->route('whatsapp.channels.index')->with('success', 'Canal excluído junto com as conversas.');
    }

    public function testChannel(Request $request, WhatsappChannel $channel, WhatsappClient $client): RedirectResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:30']]);
        @set_time_limit(60);
        try {
            $info = $client->check($channel);
            $sent = '';
            if (filled($data['phone'] ?? null)) {
                $phone = preg_replace('/\D/', '', $data['phone']);
                $client->sendText($channel, strlen($phone) <= 11 ? '55'.$phone : $phone, 'Teste do ADVOGABT: o WhatsApp do escritório está conectado.');
                $sent = ' Mensagem de teste enviada.';
            }
            $channel->forceFill(['last_test_at' => now(), 'last_test_ok' => true])->save();

            return back()->with('success', "Conexão funcionando ({$info}).{$sent}");
        } catch (WhatsappException $e) {
            $channel->forceFill(['last_test_at' => now(), 'last_test_ok' => false])->save();

            return back()->with('error', 'Falha no teste: '.$e->getMessage());
        }
    }

    private function formData(WhatsappChannel $channel): array
    {
        return [
            'channel' => $channel,
            'providers' => AiProvider::active()->orderBy('name')->get(),
            'users' => User::where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validatedChannel(Request $request, ?WhatsappChannel $channel = null): array
    {
        $driver = $request->input('driver');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(array_keys(WhatsappChannel::DRIVERS))],
            'base_url' => [$driver === 'evolution' ? 'required' : 'nullable', 'nullable', 'url:http,https', 'max:255'],
            'instance' => [$driver === 'evolution' ? 'required' : 'nullable', 'nullable', 'string', 'max:100'],
            'phone_number_id' => [$driver === 'meta' ? 'required' : 'nullable', 'nullable', 'string', 'max:50'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'app_secret' => ['nullable', 'string', 'max:200'],
            'display_phone' => ['nullable', 'string', 'max:30'],
            'ai_provider_id' => ['nullable', 'exists:ai_providers,id'],
            'greeting' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'team_user_ids' => ['nullable', 'array'],
            'team_user_ids.*' => ['integer', 'exists:users,id'],
        ], [], [
            'base_url' => 'URL do servidor', 'instance' => 'instância', 'phone_number_id' => 'Phone Number ID',
            'api_key' => $driver === 'meta' ? 'token de acesso' : 'chave da API',
        ]);

        // Chaves só mudam quando uma nova é digitada (o formulário nunca as exibe).
        foreach (['api_key', 'app_secret'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            } else {
                $data[$secret] = trim($data[$secret]);
            }
        }
        if (! isset($data['api_key']) && ! $channel?->api_key) {
            throw ValidationException::withMessages(['api_key' => $driver === 'meta' ? 'Informe o token de acesso da Meta.' : 'Informe a chave (apikey) da Evolution API.']);
        }
        if ($driver === 'meta') {
            $data['base_url'] = $data['base_url'] ?? null;
        }
        $data['team_user_ids'] = array_values(array_map('intval', $data['team_user_ids'] ?? [])) ?: null;
        foreach (['active', 'bot_enabled', 'notify_movements'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }
}
