<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DocumentTemplate;
use App\Models\LegalCase;
use App\Models\SignatureProvider;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Support\Signature\D4SignDriver;
use App\Support\Signature\SignatureException;
use App\Support\Signature\Signatures;
use App\Support\Signature\TextPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Assinatura digital: envio de contratos e procurações (equipe) e configuração das plataformas (administradores). */
class SignatureController extends Controller
{
    // --- Documentos enviados (equipe) ----------------------------------------

    public function index(Request $request): View
    {
        $requests = SignatureRequest::with(['signers', 'contact', 'legalCase', 'user'])
            ->when($request->query('situacao'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")
                ->orWhereHas('signers', fn ($s) => $s->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"))))
            ->latest('id')->paginate(20)->withQueryString();

        return view('signatures.index', [
            'requests' => $requests,
            'counts' => SignatureRequest::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'providers' => SignatureProvider::usable(),
        ]);
    }

    public function create(Request $request): View
    {
        $template = $request->filled('modelo') ? DocumentTemplate::find($request->query('modelo')) : null;
        $case = $request->filled('legal_case_id') ? LegalCase::with('client')->find($request->query('legal_case_id')) : null;
        $contact = $request->filled('contact_id') ? Contact::find($request->query('contact_id')) : $case?->client;
        $user = $request->user();

        $signers = [];
        if ($contact) {
            $signers[] = ['name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->phone, 'document' => $contact->document, 'role' => 'parte', 'auth' => 'email'];
        }
        if ($template?->category === 'contrato') {
            $signers[] = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? null, 'document' => null, 'role' => 'advogado', 'auth' => 'email'];
        }
        if (! $signers) {
            $signers[] = ['name' => '', 'email' => '', 'phone' => '', 'document' => '', 'role' => 'parte', 'auth' => 'email'];
        }

        $providers = SignatureProvider::usable();

        return view('signatures.create', [
            'template' => $template,
            'case' => $case,
            'contact' => $contact,
            'content' => $template ? $template->render($contact, $case, $user) : '',
            'title' => $template ? trim($template->name.($contact ? ' - '.$contact->name : '')) : '',
            'signers' => $signers,
            'providers' => $providers,
            'defaultProvider' => $providers->first()?->driver ?? 'manual',
            'templates' => DocumentTemplate::orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::orderBy('name')->get(['id', 'name']),
            'cases' => LegalCase::orderBy('title')->get(['id', 'title', 'number']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $usable = SignatureProvider::usable()->pluck('driver')->all();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'provider' => ['required', Rule::in([...$usable, 'manual'])],
            'source' => ['required', Rule::in(['texto', 'arquivo'])],
            'content' => ['exclude_unless:source,texto', 'required', 'string', 'max:200000'],
            'file' => ['exclude_unless:source,arquivo', 'required', 'file', 'mimes:pdf', 'max:20480'],
            'document_template_id' => ['nullable', 'exists:document_templates,id'],
            'legal_case_id' => ['nullable', 'exists:legal_cases,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'deadline_at' => ['nullable', 'date', 'after:today', 'before:+1 year'],
            'message' => ['nullable', 'string', 'max:1000'],
            'signers' => [$request->input('provider') === 'manual' ? 'nullable' : 'required', 'array', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:255'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.document' => ['nullable', 'string', 'max:20'],
            'signers.*.role' => ['required', Rule::in(array_keys(SignatureSigner::ROLES))],
            'signers.*.auth' => ['required', Rule::in(array_keys(SignatureSigner::AUTHS))],
        ], [
            'provider.in' => 'Escolha uma plataforma configurada em Integrações › Assinatura digital.',
            'signers.required' => 'Informe quem vai assinar.',
            'file.mimes' => 'Envie o documento em PDF.',
        ], [
            'title' => 'título', 'content' => 'texto do documento', 'file' => 'arquivo', 'deadline_at' => 'prazo',
            'signers.*.name' => 'nome do signatário', 'signers.*.email' => 'e-mail do signatário', 'signers.*.phone' => 'celular do signatário',
        ]);

        $signers = array_values($data['signers'] ?? []);
        $emails = array_map(fn ($s) => strtolower(trim($s['email'])), $signers);
        if (count($emails) !== count(array_unique($emails))) {
            throw ValidationException::withMessages(['signers' => 'Há signatários com o mesmo e-mail.']);
        }
        foreach ($signers as $i => $s) {
            if ($s['auth'] !== 'email' && strlen(preg_replace('/\D/', '', (string) ($s['phone'] ?? ''))) < 10) {
                throw ValidationException::withMessages(["signers.{$i}.phone" => "Informe o celular com DDD de {$s['name']} para o código por ".($s['auth'] === 'sms' ? 'SMS' : 'WhatsApp').'.']);
            }
        }

        $icp = $request->boolean('icp_brasil') || $data['provider'] === 'manual';
        $case = isset($data['legal_case_id']) ? LegalCase::find($data['legal_case_id']) : null;

        $sr = DB::transaction(function () use ($data, $signers, $icp, $case, $request) {
            $sr = SignatureRequest::create([
                'provider' => $data['provider'],
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'document_template_id' => $data['document_template_id'] ?? null,
                'legal_case_id' => $case?->id,
                'contact_id' => $data['contact_id'] ?? $case?->contact_id,
                'user_id' => $request->user()->id,
                'status' => 'enviado',
                'icp_brasil' => $icp,
                'deadline_at' => $data['provider'] === 'clicksign' ? ($data['deadline_at'] ?? null) : null,
                'file_name' => $data['source'] === 'arquivo' ? Str::limit($request->file('file')->getClientOriginalName(), 200, '') : Str::slug($data['title']).'.pdf',
            ]);
            foreach ($signers as $i => $s) {
                $sr->signers()->create([
                    'name' => trim($s['name']),
                    'email' => strtolower(trim($s['email'])),
                    'phone' => $s['phone'] ?? null,
                    'document' => $s['document'] ?? null,
                    'role' => $s['role'],
                    'auth' => $s['auth'],
                    'status' => 'pendente',
                    'position' => $i,
                ]);
            }

            $pdf = $data['source'] === 'arquivo'
                ? (string) file_get_contents($request->file('file')->getRealPath())
                : TextPdf::fromText($data['content'], $data['title'], config('app.name').' - '.$data['title']);
            $path = $sr->directory().'/original.pdf';
            Storage::disk('local')->put($path, $pdf);
            $sr->forceFill(['file_path' => $path])->save();

            return $sr;
        });

        try {
            @set_time_limit(120);
            Signatures::send($sr);
        } catch (SignatureException $e) {
            return redirect()->route('signatures.show', $sr)->with('error', 'O documento foi salvo, mas não foi enviado: '.$e->getMessage());
        }

        return redirect()->route('signatures.show', $sr)->with('success', $sr->isManual()
            ? 'Documento pronto. Baixe o PDF, assine com o certificado digital e anexe aqui a versão assinada.'
            : 'Documento enviado pela '.$sr->provider_label.'. Os signatários recebem o link para assinar.');
    }

    public function show(SignatureRequest $signature): View
    {
        $signature->load(['signers', 'contact', 'legalCase', 'user', 'template']);
        $provider = $signature->isManual() ? null : SignatureProvider::forDriver($signature->provider);

        return view('signatures.show', [
            'sr' => $signature,
            'provider' => $provider,
            'localHost' => $provider ? Signatures::isLocalUrl($provider->webhook_url) : false,
        ]);
    }

    public function refresh(SignatureRequest $signature): RedirectResponse
    {
        try {
            $signature->status === 'assinado' && ! $signature->signed_path && ! $signature->isManual()
                ? Signatures::complete($signature)
                : Signatures::refresh($signature);
        } catch (SignatureException $e) {
            $signature->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage()])->save();

            return back()->with('error', $e->getMessage());
        }
        $signature->refresh();

        return back()->with('success', 'Situação atualizada: '.mb_strtolower($signature->status_label).'.');
    }

    public function resend(SignatureRequest $signature): RedirectResponse
    {
        abort_unless($signature->status === 'erro', 422, 'Só documentos com falha no envio podem ser reenviados.');
        if ($signature->provider !== 'manual' && ! SignatureProvider::forDriver($signature->provider)?->isConfigured()) {
            return back()->with('error', 'A plataforma deste documento não está mais configurada.');
        }
        // Começa do zero na plataforma (o envio anterior pode ter parado no meio).
        $signature->forceFill(['external_id' => null, 'external_document_id' => null])->save();
        $signature->signers()->update(['external_id' => null, 'status' => 'pendente', 'signed_at' => null]);
        try {
            @set_time_limit(120);
            Signatures::send($signature);
        } catch (SignatureException $e) {
            return back()->with('error', 'Não foi enviado: '.$e->getMessage());
        }

        return back()->with('success', 'Documento enviado.');
    }

    public function cancel(Request $request, SignatureRequest $signature): RedirectResponse
    {
        $this->authorizeOwner($request, $signature);
        abort_unless(in_array($signature->status, ['enviado', 'erro'], true), 422, 'Este documento não pode mais ser cancelado.');
        try {
            Signatures::cancel($signature);
        } catch (SignatureException $e) {
            return back()->with('error', 'A plataforma não cancelou: '.$e->getMessage());
        }

        return back()->with('success', 'Envio cancelado. Os signatários não conseguem mais assinar.');
    }

    public function destroy(Request $request, SignatureRequest $signature): RedirectResponse
    {
        $this->authorizeOwner($request, $signature);
        abort_if($signature->status === 'enviado' && ! $signature->isManual(), 422, 'Cancele o envio antes de excluir.');
        Storage::disk('local')->deleteDirectory($signature->directory());
        $signature->delete();
        Signatures::forget();

        return redirect()->route('signatures.index')->with('success', 'Documento excluído do sistema.');
    }

    /** ICP-Brasil com assinador próprio: anexa o PDF assinado. */
    public function attachSigned(Request $request, SignatureRequest $signature): RedirectResponse
    {
        abort_unless($signature->isManual() && $signature->status === 'enviado', 422);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:30720']], ['file.mimes' => 'Envie o PDF assinado.'], ['file' => 'arquivo']);
        $pdf = (string) file_get_contents($request->file('file')->getRealPath());
        $check = Signatures::inspect($pdf);
        if ($check['signatures'] === 0 && ! $request->boolean('sem_certificado')) {
            return back()->with('error', 'O PDF não tem assinatura digital. Assine com o certificado (Assinador SERPRO, Adobe, PJeOffice...) e envie de novo, ou marque "documento assinado à mão e digitalizado".');
        }

        Signatures::complete($signature, $pdf);
        $note = $check['signatures'] === 0 ? 'sem assinatura digital (digitalizado)'
            : $check['signatures'].' assinatura(s) digital(is)'.($check['icp'] ? ', certificado ICP-Brasil' : ', certificado não ICP-Brasil');
        $signature->forceFill(['last_error' => null, 'message' => trim(($signature->message ? $signature->message."\n" : '').'Verificação: '.$note.'.')])->save();

        return back()->with($check['signatures'] && ! $check['icp'] ? 'error' : 'success', 'PDF assinado guardado ('.$note.').'
            .($check['signatures'] && ! $check['icp'] ? ' Atenção: o certificado não parece ser ICP-Brasil.' : ''));
    }

    public function download(SignatureRequest $signature, string $kind): StreamedResponse
    {
        $path = $kind === 'assinado' ? $signature->signed_path : $signature->file_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $base = Str::slug($signature->title) ?: 'documento';

        return Storage::disk('local')->download($path, $base.($kind === 'assinado' ? '-assinado' : '').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function authorizeOwner(Request $request, SignatureRequest $sr): void
    {
        abort_unless($request->user()->isAdmin() || $sr->user_id === $request->user()->id, 403);
    }

    // --- Configuração das plataformas (administradores) ----------------------

    public function settings(): View
    {
        $providers = [];
        foreach (array_keys(SignatureProvider::DRIVERS) as $driver) {
            $providers[$driver] = SignatureProvider::forDriver($driver) ?? new SignatureProvider(['driver' => $driver, 'environment' => 'sandbox', 'active' => true]);
        }

        return view('signatures.settings', [
            'providers' => $providers,
            'pending' => SignatureRequest::open()->count(),
            'signedMonth' => SignatureRequest::where('status', 'assinado')->where('completed_at', '>=', now()->startOfMonth())->count(),
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        abort_unless(isset(SignatureProvider::DRIVERS[$driver]), 404);
        $p = SignatureProvider::forDriver($driver) ?? new SignatureProvider(['driver' => $driver]);
        $data = $request->validate([
            'environment' => ['required', Rule::in(array_keys(SignatureProvider::ENVIRONMENTS))],
            'api_token' => [$p->api_token ? 'nullable' : 'required', 'nullable', 'string', 'max:500'],
            'crypt_key' => [$driver === 'd4sign' && ! $p->crypt_key ? 'required' : 'nullable', 'nullable', 'string', 'max:500'],
            'safe_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'safe_name' => ['nullable', 'string', 'max:255'],
        ], [], ['api_token' => $driver === 'd4sign' ? 'Token API' : 'chave de acesso', 'crypt_key' => 'Crypt Key', 'safe_uuid' => 'cofre']);

        $credentialsChanged = $p->exists && ($p->environment !== $data['environment'] || filled($data['api_token'] ?? null) || filled($data['crypt_key'] ?? null));
        $p->fill([
            'environment' => $data['environment'],
            'safe_uuid' => $driver === 'd4sign' ? ($data['safe_uuid'] ?? null) : null,
            'safe_name' => $driver === 'd4sign' ? ($data['safe_name'] ?? null) : null,
            'active' => $request->boolean('active'),
            'is_default' => $request->boolean('is_default'),
        ]);
        if (filled($data['api_token'] ?? null)) {
            $p->api_token = trim($data['api_token']);
        }
        if (filled($data['crypt_key'] ?? null)) {
            $p->crypt_key = trim($data['crypt_key']);
        }
        if ($credentialsChanged) {
            $p->forceFill(['last_test_at' => null, 'last_test_ok' => null, 'last_error' => null]);
        }
        $p->save();
        if ($p->is_default) {
            SignatureProvider::where('id', '!=', $p->id)->update(['is_default' => false]);
        }

        $message = $p->label.' salva.';
        if ($driver === 'd4sign' && ! $p->safe_uuid) {
            $message .= ' Agora clique em "Carregar cofres", escolha o cofre e salve de novo.';
        } else {
            $message .= ' Use "Testar conexão" para conferir.';
        }

        return redirect()->route('signatures.settings')->with('success', $message);
    }

    public function testProvider(string $driver): RedirectResponse
    {
        $p = SignatureProvider::forDriver($driver);
        if (! $p?->api_token) {
            return back()->with('error', 'Salve a chave de acesso antes de testar.');
        }
        try {
            $info = Signatures::driver($driver)->test($p);
            $p->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'last_error' => null])->save();

            return back()->with('success', $info);
        } catch (SignatureException $e) {
            $p->forceFill(['last_test_at' => now(), 'last_test_ok' => false, 'last_error' => $e->getMessage()])->save();

            return back()->with('error', $e->getMessage());
        }
    }

    /** Lista os cofres da conta D4Sign (botão "Carregar cofres"). */
    public function safes(Request $request): JsonResponse
    {
        $p = SignatureProvider::forDriver('d4sign') ?? new SignatureProvider(['driver' => 'd4sign']);
        $p->environment = $request->input('environment', $p->environment ?: 'sandbox');
        if ($request->filled('api_token')) {
            $p->api_token = trim((string) $request->input('api_token'));
        }
        if ($request->filled('crypt_key')) {
            $p->crypt_key = trim((string) $request->input('crypt_key'));
        }
        if (! $p->api_token || ! $p->crypt_key) {
            return response()->json(['message' => 'Informe o Token API e a Crypt Key.'], 422);
        }
        try {
            $safes = app(D4SignDriver::class)->safes($p);
        } catch (SignatureException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['safes' => collect($safes)->map(fn ($name, $uuid) => ['uuid' => $uuid, 'name' => $name])->values()]);
    }

    public function disconnect(string $driver): RedirectResponse
    {
        $p = SignatureProvider::forDriver($driver);
        abort_unless($p, 404);
        if (SignatureRequest::open()->where('provider', $driver)->exists()) {
            $p->forceFill(['active' => false])->save();

            return back()->with('success', $p->label.' desativada para novos envios. Os documentos que aguardam assinatura continuam sendo acompanhados.');
        }
        $p->delete();

        return back()->with('success', $p->label.' desconectada e chaves apagadas.');
    }

    // --- Aviso das plataformas -----------------------------------------------

    public function webhook(Request $request, string $token): JsonResponse
    {
        $p = SignatureProvider::where('webhook_token', $token)->first();
        abort_unless($p, 404);
        $p->forceFill(['last_webhook_at' => now()])->saveQuietly();
        $payload = $request->json()->all() ?: $request->all();
        try {
            $count = Signatures::handleWebhook($p, is_array($payload) ? $payload : []);
        } catch (\Throwable $e) {
            report($e);
            $count = 0;
        }

        return response()->json(['ok' => true, 'documentos' => $count]);
    }
}
