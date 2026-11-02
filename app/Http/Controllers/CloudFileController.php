<?php

namespace App\Http\Controllers;

use App\Models\CloudStorage;
use App\Support\Cloud\CloudClient;
use App\Support\Cloud\CloudException;
use App\Support\Cloud\CloudFolders;
use App\Support\Cloud\Rclone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Arquivos na nuvem: configuração (administradores) e a pasta de cada processo e cliente (equipe). */
class CloudFileController extends Controller
{
    public function __construct(private CloudFolders $folders) {}

    // --- Configuração (administradores) -------------------------------------

    public function settings(Request $request): View
    {
        $storage = CloudStorage::current() ?? new CloudStorage([
            'driver' => 'google',
            'auth_mode' => 'rclone',
            'tenant' => 'common',
            'access' => 'completo',
            'root_name' => 'ADVOGABT',
            'case_layout' => 'cliente',
            'subfolders' => CloudStorage::DEFAULT_SUBFOLDERS,
            'auto_create' => true,
            'copy_whatsapp' => true,
        ]);
        $tipo = $request->query('tipo');
        if (in_array($tipo, array_keys(CloudStorage::DRIVERS), true) && $tipo !== $storage->driver) {
            // Trocar de nuvem: mostra o formulário limpo; ao salvar, a conexão atual é substituída.
            $storage = new CloudStorage($storage->only(['root_name', 'case_layout', 'subfolders', 'auto_create', 'copy_whatsapp']) + [
                'driver' => $tipo, 'auth_mode' => $storage->auth_mode ?? 'rclone', 'tenant' => 'common', 'access' => 'completo',
            ]);
        }

        return view('cloud.settings', [
            'storage' => $storage,
            'suggestedRedirect' => CloudStorage::suggestedRedirectUri(),
            'folders' => $storage->exists ? $storage->folders()->count() : 0,
            'missing' => $storage->isConnected() ? $this->folders->missingCount($storage) : 0,
            'rclone' => Rclone::version(),
            'rcloneCommand' => Rclone::authorizeCommand($storage),
            'callbackError' => $request->query('erro'),
            'callbackOk' => $request->boolean('conectado'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $storage = CloudStorage::current() ?? new CloudStorage;
        $app = $request->input('auth_mode') === 'app';
        $keepSecret = $storage->client_secret && $request->input('driver') === $storage->driver;
        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys(CloudStorage::DRIVERS))],
            'auth_mode' => ['required', Rule::in(array_keys(CloudStorage::AUTH_MODES))],
            'client_id' => [$app ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'client_secret' => [$app && ! $keepSecret ? 'required' : 'nullable', 'nullable', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:100', 'regex:/^[\w.-]+$/'],
            'access' => ['nullable', Rule::in(array_keys(CloudStorage::ACCESS))],
            'redirect_uri' => [$app ? 'required' : 'nullable', 'nullable', 'url:http,https', 'max:255'],
            'root_name' => ['required', 'string', 'max:100'],
            'root_link' => ['nullable', 'string', 'max:500'],
            'case_layout' => ['required', Rule::in(array_keys(CloudStorage::LAYOUTS))],
            'subfolders' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'client_id' => 'ID do cliente', 'client_secret' => 'chave secreta', 'tenant' => 'locatário',
            'redirect_uri' => 'URL de retorno', 'root_name' => 'pasta principal', 'subfolders' => 'subpastas',
        ]);

        $identity = ['driver', 'auth_mode', 'client_id', 'tenant', 'access'];
        $before = $storage->only($identity);
        $storage->fill([
            'driver' => $data['driver'],
            'auth_mode' => $data['auth_mode'],
            'client_id' => $app ? trim($data['client_id']) : $storage->client_id,
            'tenant' => $data['driver'] === 'onedrive' ? ($data['tenant'] ?: 'common') : null,
            'access' => $data['driver'] === 'google' ? ($data['access'] ?? 'completo') : 'completo',
            'redirect_uri' => $app ? trim($data['redirect_uri']) : $storage->redirect_uri,
            'case_layout' => $data['case_layout'],
            'subfolders' => $data['subfolders'] ?? null,
            'auto_create' => $request->boolean('auto_create'),
            'copy_whatsapp' => $request->boolean('copy_whatsapp'),
        ]);
        if (filled($data['client_secret'] ?? null)) {
            $storage->client_secret = trim($data['client_secret']);
        }
        $rootChanged = $storage->root_name !== $data['root_name'];
        $storage->root_name = $data['root_name'];

        $message = 'Configuração salva.';
        $reconnect = $storage->exists && $before !== $storage->only($identity);
        if ($reconnect) {
            if ($before['driver'] !== $storage->driver) {
                $storage->folders()->delete(); // ids de outra nuvem não servem mais
                $storage->forceFill(['root_folder_id' => null, 'root_folder_url' => null, 'drive_id' => null, 'drive_type' => null, 'folder_cache' => null]);
            }
            $storage->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'connected_at' => null]);
            $message = 'Configuração salva. Como a nuvem ou a forma de conexão mudou, conecte a conta de novo.';
        }
        $storage->save();
        if ($storage->usesRclone() && ! $storage->isConnected()) {
            $message .= ' Agora siga os 3 passos do quadro "Conectar a conta" para entrar na conta.';
        }

        if ($storage->isConnected() && (filled($data['root_link'] ?? null) || $rootChanged)) {
            try {
                $item = $this->folders->setupRoot($storage, $data['root_link'] ?? null);
                $message .= " Pasta principal: {$item['name']}.";
            } catch (CloudException $e) {
                return back()->withInput()->with('error', 'Configuração salva, mas a pasta principal não foi alterada: '.$e->getMessage());
            }
        }

        return redirect()->route('cloud.settings')->with('success', $message);
    }

    /** Leva o administrador ao login do Google/Microsoft. */
    public function connect(CloudClient $client): RedirectResponse
    {
        $storage = CloudStorage::current();
        if (! $storage || $storage->usesRclone() || blank($storage->client_id) || blank($storage->client_secret)) {
            return redirect()->route('cloud.settings')->with('error', 'Preencha e salve o ID do cliente e a chave secreta antes de conectar.');
        }
        $nonce = Str::random(40);
        $storage->forceFill(['oauth_state' => $nonce, 'return_url' => route('cloud.settings')])->save();

        return redirect()->away($client->authorizationUrl($storage, $storage->id.'.'.$nonce));
    }

    /**
     * Retorno do login. Fica fora do login do sistema porque pode chegar por outro endereço
     * (ex.: http://localhost no Laragon); a segurança vem do "state" de uso único.
     */
    public function callback(Request $request, CloudClient $client): RedirectResponse
    {
        [$id, $nonce] = array_pad(explode('.', (string) $request->query('state'), 2), 2, '');
        $storage = CloudStorage::find((int) $id);
        if (! $storage || blank($storage->oauth_state) || ! hash_equals($storage->oauth_state, $nonce)) {
            abort(403, 'Link de retorno inválido ou já usado. Volte ao ADVOGABT e clique em "Conectar" de novo.');
        }
        $back = $storage->return_url ?: route('cloud.settings');
        $storage->forceFill(['oauth_state' => null])->save();

        if ($request->filled('error')) {
            $error = Str::limit((string) ($request->query('error_description') ?: $request->query('error')), 300);

            return redirect()->away($back.'?'.http_build_query(['erro' => 'O login foi cancelado ou recusado: '.$error]));
        }

        try {
            $client->exchangeCode($storage, (string) $request->query('code'));
            $this->folders->setupRoot($storage);
            $storage->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'last_error' => null])->save();
        } catch (CloudException $e) {
            $storage->forceFill(['last_error' => $e->getMessage(), 'last_test_ok' => false])->save();

            return redirect()->away($back.'?'.http_build_query(['erro' => $e->getMessage()]));
        }

        return redirect()->away($back.'?conectado=1');
    }

    /** Conexão rápida: recebe o token gerado pelo "rclone authorize". */
    public function pasteToken(Request $request, CloudClient $client): RedirectResponse
    {
        $storage = CloudStorage::current();
        if (! $storage?->usesRclone()) {
            return redirect()->route('cloud.settings')->with('error', 'Salve a configuração com a conexão rápida (rclone) antes de colar o token.');
        }
        $data = $request->validate(['token' => ['required', 'string', 'max:20000']], [], ['token' => 'token']);
        @set_time_limit(120);
        try {
            $client->connectWithRcloneToken($storage, $data['token']);
            $item = $this->folders->setupRoot($storage);
            $storage->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'last_error' => null])->save();
        } catch (CloudException $e) {
            $storage->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'last_error' => $e->getMessage()])->save();

            return back()->withErrors(['token' => $e->getMessage()]);
        }
        $warning = Rclone::path() ? '' : ' Atenção: instale o rclone neste servidor, senão a conexão para de funcionar em 1 hora (é ele que renova o acesso).';

        return redirect()->route('cloud.settings')->with('success', "{$storage->driver_label} conectado".($storage->account_email ? " como {$storage->account_email}" : '').". Pasta principal: {$item['name']}.".$warning);
    }

    public function installRclone(): RedirectResponse
    {
        @set_time_limit(600);
        try {
            $path = Rclone::install();
        } catch (CloudException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'rclone instalado: '.(Rclone::version() ?? $path).'.');
    }

    public function test(CloudClient $client): RedirectResponse
    {
        $storage = CloudStorage::current();
        abort_unless($storage, 404);
        @set_time_limit(60);
        try {
            $account = $client->account($storage);
            $root = $client->item($storage, (string) $storage->root_folder_id);
            $storage->forceFill([
                'account_name' => $account['name'], 'account_email' => $account['email'],
                'root_folder_url' => $root['url'], 'last_test_at' => now(), 'last_test_ok' => true, 'last_error' => null,
            ])->save();

            return back()->with('success', "Conexão funcionando: {$account['email']}, pasta \"{$root['name']}\"".($account['quota'] ? " ({$account['quota']})." : '.'));
        } catch (CloudException $e) {
            if ($e->notFound && $storage->isConnected()) {
                try {
                    $item = $this->folders->setupRoot($storage);

                    return back()->with('success', "A pasta principal tinha sido removida e foi criada de novo: {$item['name']}.");
                } catch (CloudException) {
                }
            }
            $storage->forceFill(['last_test_at' => now(), 'last_test_ok' => false, 'last_error' => $e->getMessage()])->save();

            return back()->with('error', 'Falha no teste: '.$e->getMessage());
        }
    }

    public function disconnect(CloudClient $client): RedirectResponse
    {
        $storage = CloudStorage::current();
        abort_unless($storage, 404);
        $client->revoke($storage);
        $storage->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'connected_at' => null])->save();

        return back()->with('success', "{$storage->driver_label} desconectado. As pastas e os arquivos continuam na nuvem; conecte de novo para voltar a usá-los.");
    }

    public function createMissing(): RedirectResponse
    {
        $storage = CloudStorage::connected();
        abort_unless($storage, 404);
        @set_time_limit(300);
        $r = $this->folders->createMissing($storage);
        $left = $this->folders->missingCount($storage);
        $msg = "Pastas prontas: {$r['contatos']} cliente(s) e {$r['processos']} processo(s).".($left ? " Faltam {$left}; clique de novo para continuar." : '');
        if ($r['erros']) {
            return back()->with('error', $msg.' Erros: '.Str::limit(implode(' | ', $r['erros']), 500));
        }

        return back()->with('success', $msg);
    }

    // --- Pasta do processo / cliente (equipe) -------------------------------

    /** Conteúdo da pasta, carregado depois da página (a nuvem pode demorar a responder). */
    public function panel(string $type, int $id): JsonResponse
    {
        $owner = CloudFolders::owner($type, $id);
        $storage = CloudStorage::connected();
        $data = ['storage' => $storage, 'type' => $type, 'owner' => $owner, 'folder' => null, 'items' => [], 'error' => null];

        if ($storage) {
            try {
                $folder = $this->folders->mapping($storage, $owner);
                $contents = $folder ? $this->folders->contents($storage, $folder) : null;
                if ($contents) {
                    $data['folder'] = $folder->fresh();
                    $data['items'] = $contents['items'];
                }
            } catch (CloudException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return response()->json(['html' => view('cloud._files', $data + ['client' => $this->folders->client()])->render()]);
    }

    public function createFolder(string $type, int $id): RedirectResponse
    {
        $storage = $this->storageOrFail();
        try {
            $folder = $this->folders->ensure($storage, CloudFolders::owner($type, $id));
        } catch (CloudException $e) {
            return back()->with('error', 'A pasta não foi criada: '.$e->getMessage());
        }

        return back()->with('success', "Pasta \"{$folder->name}\" pronta no {$storage->driver_label}.");
    }

    public function linkFolder(Request $request, string $type, int $id): RedirectResponse
    {
        $storage = $this->storageOrFail();
        $data = $request->validate(['link' => ['required', 'string', 'max:500']], [], ['link' => 'link da pasta']);
        try {
            $folder = $this->folders->link($storage, CloudFolders::owner($type, $id), $data['link']);
        } catch (CloudException $e) {
            return back()->with('error', 'A pasta não foi vinculada: '.$e->getMessage());
        }

        return back()->with('success', "Pasta \"{$folder->name}\" vinculada.");
    }

    public function unlinkFolder(string $type, int $id): RedirectResponse
    {
        $storage = $this->storageOrFail();
        $this->folders->mapping($storage, CloudFolders::owner($type, $id))?->delete();

        return back()->with('success', 'Vínculo removido. A pasta e os arquivos continuam na nuvem.');
    }

    public function upload(Request $request, string $type, int $id): RedirectResponse
    {
        $storage = $this->storageOrFail();
        $owner = CloudFolders::owner($type, $id);
        $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:256000'],
            'target' => ['nullable', 'string', 'max:200'],
        ], ['files.required' => 'Escolha pelo menos um arquivo.', 'files.*.uploaded' => 'O arquivo passou do limite de envio do servidor ('.self::uploadLimit().').'], ['files' => 'arquivos', 'files.*' => 'arquivo']);

        @set_time_limit(600);
        try {
            $folder = $this->folders->ensure($storage, $owner);
            $target = $folder->remote_id;
            if ($request->filled('target') && $request->input('target') !== $folder->remote_id) {
                $sub = $this->folders->client()->item($storage, $request->input('target'));
                abort_unless($sub['folder'] && in_array($folder->remote_id, $sub['parents'], true), 403);
                $target = $sub['id'];
            }
            $sent = [];
            foreach ($request->file('files') as $file) {
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    $item = $this->folders->client()->upload($storage, $target, CloudFolders::safeName($file->getClientOriginalName()), $stream, $file->getMimeType());
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $sent[] = $item['name'];
            }
        } catch (CloudException $e) {
            return back()->with('error', 'Envio interrompido: '.$e->getMessage().(isset($sent) && $sent ? ' Já enviados: '.implode(', ', $sent).'.' : ''));
        }

        return back()->with('success', count($sent) === 1 ? "Arquivo \"{$sent[0]}\" enviado para o {$storage->driver_label}." : count($sent)." arquivos enviados para o {$storage->driver_label}.");
    }

    public function trash(string $type, int $id, string $file): RedirectResponse
    {
        $storage = $this->storageOrFail();
        $folder = $this->folders->mapping($storage, CloudFolders::owner($type, $id));
        abort_unless($folder, 404);
        try {
            $item = $this->folders->client()->item($storage, $file);
            abort_unless(in_array($folder->remote_id, $item['parents'], true), 403);
            $this->folders->client()->trash($storage, $file);
        } catch (CloudException $e) {
            return back()->with('error', 'Não foi possível mover para a lixeira: '.$e->getMessage());
        }

        return back()->with('success', "\"{$item['name']}\" foi para a lixeira do {$storage->driver_label} (dá para recuperar por lá).");
    }

    /** Limite efetivo de envio do PHP (upload_max_filesize / post_max_size). */
    public static function uploadLimit(): string
    {
        $toBytes = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };
        $limits = array_filter([$toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size'))]);

        return $limits ? app(CloudClient::class)->bytes(min($limits)) : 'sem limite';
    }

    private function storageOrFail(): CloudStorage
    {
        $storage = CloudStorage::connected();
        if (! $storage) {
            abort(409, 'Os arquivos na nuvem não estão conectados. Um administrador precisa conectar em Integrações › Arquivos na nuvem.');
        }

        return $storage;
    }
}
