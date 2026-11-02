<?php

namespace App\Support\Cloud;

use App\Models\CloudStorage;
use App\Support\Ai\LlmClient;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fala com o Google Drive (API v3) e com o OneDrive (Microsoft Graph): login OAuth,
 * renovação do token, pastas, listagem, envio de arquivos e lixeira.
 *
 * Itens são devolvidos como arrays: id, name, folder (bool), size, modified, url, mime.
 */
class CloudClient
{
    public const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

    public const GOOGLE_API = 'https://www.googleapis.com/drive/v3';

    public const GOOGLE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    public const GOOGLE_FOLDER = 'application/vnd.google-apps.folder';

    public const MS_LOGIN = 'https://login.microsoftonline.com';

    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    public const MS_SCOPES = 'offline_access User.Read Files.ReadWrite.All';

    private const GOOGLE_FIELDS = 'id,name,mimeType,size,modifiedTime,webViewLink,trashed,parents';

    private const MS_SELECT = 'id,name,size,lastModifiedDateTime,webUrl,folder,file,parentReference';

    // --- Login (OAuth 2.0) ---------------------------------------------------

    public function authorizationUrl(CloudStorage $s, string $state): string
    {
        if ($s->driver === 'google') {
            return self::GOOGLE_AUTH.'?'.http_build_query([
                'client_id' => $s->client_id,
                'redirect_uri' => $s->redirect_uri,
                'response_type' => 'code',
                'scope' => $this->googleScope($s),
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'state' => $state,
            ]);
        }

        return $this->msBase($s).'/authorize?'.http_build_query([
            'client_id' => $s->client_id,
            'redirect_uri' => $s->redirect_uri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => self::MS_SCOPES,
            'prompt' => 'select_account',
            'state' => $state,
        ]);
    }

    /** Troca o código do login pelos tokens e lê a conta conectada. */
    public function exchangeCode(CloudStorage $s, string $code): void
    {
        $this->storeTokens($s, $this->tokenRequest($s, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $s->redirect_uri,
        ]));
        if (blank($s->refresh_token)) {
            throw new CloudException('O login não devolveu a autorização permanente (refresh token). Remova o acesso do ADVOGABT na sua conta e conecte de novo.');
        }

        $account = $this->account($s);
        $s->forceFill([
            'account_name' => $account['name'],
            'account_email' => $account['email'],
            'drive_id' => $account['drive_id'],
            'connected_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /** Desfaz a autorização na conta (melhor esforço). */
    public function revoke(CloudStorage $s): void
    {
        if ($s->driver === 'google' && filled($s->refresh_token)) {
            try {
                $this->http()->asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $s->refresh_token]);
            } catch (\Throwable) {
                // a Microsoft não tem revogação por API; no Google, se falhar, o usuário remove pela conta
            }
        }
    }

    /** @return array{name: ?string, email: ?string, drive_id: ?string, quota: ?string} */
    public function account(CloudStorage $s): array
    {
        if ($s->driver === 'google') {
            $r = $this->guard($this->call(fn () => $this->api($s)->get(self::GOOGLE_API.'/about', ['fields' => 'user(displayName,emailAddress),storageQuota(limit,usage)'])));
            $limit = $r->json('storageQuota.limit');

            return [
                'name' => $r->json('user.displayName'),
                'email' => $r->json('user.emailAddress'),
                'drive_id' => null,
                'quota' => $limit ? $this->bytes((int) $r->json('storageQuota.usage')).' de '.$this->bytes((int) $limit).' usados' : null,
            ];
        }

        $me = $this->guard($this->call(fn () => $this->api($s)->get(self::GRAPH.'/me', ['$select' => 'displayName,mail,userPrincipalName'])));
        $drive = $this->guard($this->call(fn () => $this->api($s)->get(self::GRAPH.'/me/drive', ['$select' => 'id,quota,driveType'])));
        $total = $drive->json('quota.total');

        return [
            'name' => $me->json('displayName'),
            'email' => $me->json('mail') ?: $me->json('userPrincipalName'),
            'drive_id' => $drive->json('id'),
            'quota' => $total ? $this->bytes((int) $drive->json('quota.used')).' de '.$this->bytes((int) $total).' usados' : null,
        ];
    }

    // --- Pastas e arquivos ---------------------------------------------------

    /** Acha a subpasta com este nome dentro de $parentId, ou cria. $parentId null = raiz do drive. */
    public function ensureFolder(CloudStorage $s, string $name, ?string $parentId): array
    {
        return $this->findFolder($s, $name, $parentId) ?? $this->createFolder($s, $name, $parentId);
    }

    public function findFolder(CloudStorage $s, string $name, ?string $parentId): ?array
    {
        if ($s->driver === 'google') {
            $q = sprintf("name = '%s' and '%s' in parents and mimeType = '%s' and trashed = false",
                $this->gq($name), $this->gq($parentId ?? 'root'), self::GOOGLE_FOLDER);
            $r = $this->guard($this->call(fn () => $this->api($s)->get(self::GOOGLE_API.'/files', $this->googleListParams($q, 5))));
            $file = $r->json('files.0');

            return $file ? $this->googleItem($file) : null;
        }

        $r = $this->call(fn () => $this->api($s)->get($this->msItem($s, $parentId).':/'.rawurlencode($name), ['$select' => self::MS_SELECT]));
        if ($r->status() === 404) {
            return null;
        }
        $item = $this->guard($r)->json();

        return isset($item['folder']) ? $this->msItemArray($item) : null;
    }

    public function createFolder(CloudStorage $s, string $name, ?string $parentId): array
    {
        if ($s->driver === 'google') {
            $r = $this->guard($this->call(fn () => $this->api($s)->post(self::GOOGLE_API.'/files?'.http_build_query(['fields' => self::GOOGLE_FIELDS, 'supportsAllDrives' => 'true']), [
                'name' => $name,
                'mimeType' => self::GOOGLE_FOLDER,
                'parents' => [$parentId ?? 'root'],
            ])));

            return $this->googleItem($r->json());
        }

        $r = $this->guard($this->call(fn () => $this->api($s)->post($this->msItem($s, $parentId).'/children', [
            'name' => $name,
            'folder' => new \stdClass,
            '@microsoft.graph.conflictBehavior' => 'rename',
        ])));

        return $this->msItemArray($r->json());
    }

    /** Dados de uma pasta ou arquivo. Lança CloudException(notFound) se não existir ou estiver na lixeira. */
    public function item(CloudStorage $s, string $id): array
    {
        if ($s->driver === 'google') {
            $r = $this->call(fn () => $this->api($s)->get(self::GOOGLE_API.'/files/'.rawurlencode($id), ['fields' => self::GOOGLE_FIELDS, 'supportsAllDrives' => 'true']));
            if ($r->status() === 404) {
                throw CloudException::notFound('A pasta não foi encontrada no Google Drive.');
            }
            $file = $this->guard($r)->json();
            if (! empty($file['trashed'])) {
                throw CloudException::notFound('A pasta está na lixeira do Google Drive.');
            }

            return $this->googleItem($file);
        }

        $r = $this->call(fn () => $this->api($s)->get($this->msItem($s, $id), ['$select' => self::MS_SELECT]));
        if ($r->status() === 404) {
            throw CloudException::notFound('A pasta não foi encontrada no OneDrive.');
        }

        return $this->msItemArray($this->guard($r)->json());
    }

    /** Aceita o link de uma pasta (como aparece no navegador) ou o id dela. */
    public function resolveLink(CloudStorage $s, string $link): array
    {
        $link = trim($link);
        if ($s->driver === 'google') {
            $id = preg_match('#/folders/([\w-]+)#', $link, $m) ? $m[1]
                : (preg_match('#[?&]id=([\w-]+)#', $link, $m) ? $m[1] : $link);
            if (! preg_match('/^[\w-]{10,}$/', $id)) {
                throw new CloudException('Cole o link da pasta do Google Drive (https://drive.google.com/drive/folders/...).');
            }
            $item = $this->item($s, $id);
        } elseif (str_starts_with($link, 'http')) {
            $share = 'u!'.rtrim(strtr(base64_encode($link), '+/', '-_'), '=');
            $r = $this->call(fn () => $this->api($s)->get(self::GRAPH.'/shares/'.$share.'/driveItem', ['$select' => self::MS_SELECT]));
            if (in_array($r->status(), [400, 403, 404], true)) {
                throw CloudException::notFound('O OneDrive não encontrou a pasta desse link. Use o link "Copiar link" da pasta, aberto pela conta conectada.');
            }
            $item = $this->msItemArray($this->guard($r)->json());
        } else {
            $item = $this->item($s, $link);
        }
        if (! $item['folder']) {
            throw new CloudException('O link é de um arquivo, não de uma pasta.');
        }

        return $item;
    }

    /** @return list<array> pastas primeiro, depois arquivos, em ordem alfabética */
    public function children(CloudStorage $s, string $folderId, int $limit = 200): array
    {
        $items = [];
        if ($s->driver === 'google') {
            $q = sprintf("'%s' in parents and trashed = false", $this->gq($folderId));
            $r = $this->guard($this->call(fn () => $this->api($s)->get(self::GOOGLE_API.'/files', $this->googleListParams($q, $limit) + ['orderBy' => 'folder,name_natural'])));
            foreach ($r->json('files') ?? [] as $file) {
                $items[] = $this->googleItem($file);
            }
        } else {
            $r = $this->guard($this->call(fn () => $this->api($s)->get($this->msItem($s, $folderId).'/children', ['$select' => self::MS_SELECT, '$top' => $limit])));
            foreach ($r->json('value') ?? [] as $item) {
                $items[] = $this->msItemArray($item);
            }
        }

        usort($items, fn ($a, $b) => [$b['folder'], mb_strtolower($a['name'])] <=> [$a['folder'], mb_strtolower($b['name'])]);

        return $items;
    }

    /** Envia um arquivo para a pasta. $contents pode ser texto ou um recurso (fopen). */
    public function upload(CloudStorage $s, string $folderId, string $name, $contents, ?string $mime = null): array
    {
        $mime = $mime ?: 'application/octet-stream';
        $size = is_resource($contents) ? (fstat($contents)['size'] ?? null) : strlen($contents);
        $body = is_resource($contents) ? Utils::streamFor($contents) : $contents;

        if ($s->driver === 'google') {
            // Envio "resumable": 1) abre a sessão com os metadados, 2) manda o conteúdo.
            $start = $this->guard($this->call(fn () => $this->api($s)
                ->withHeaders(array_filter(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => $size]))
                ->post(self::GOOGLE_UPLOAD.'/files?'.http_build_query(['uploadType' => 'resumable', 'supportsAllDrives' => 'true', 'fields' => self::GOOGLE_FIELDS]), [
                    'name' => $name,
                    'parents' => [$folderId],
                ])));
            $session = $start->header('Location');
            if (! $session) {
                throw new CloudException('O Google Drive não abriu a sessão de envio.');
            }
            $r = $this->guard($this->call(fn () => $this->api($s, 600)->withBody($body, $mime)->put($session)));

            return $this->googleItem($r->json());
        }

        // Envio simples do Microsoft Graph: até 250 MB.
        if ($size !== null && $size > 250 * 1024 * 1024) {
            throw new CloudException('O OneDrive aceita arquivos de até 250 MB por este envio.');
        }
        $url = $this->msItem($s, $folderId).':/'.rawurlencode($name).':/content?'.http_build_query(['@microsoft.graph.conflictBehavior' => 'rename']);
        $r = $this->guard($this->call(fn () => $this->api($s, 600)->withBody($body, $mime)->put($url)));

        return $this->msItemArray($r->json());
    }

    /** Move para a lixeira (dá para recuperar pela própria nuvem). */
    public function trash(CloudStorage $s, string $id): void
    {
        if ($s->driver === 'google') {
            $this->guard($this->call(fn () => $this->api($s)->patch(self::GOOGLE_API.'/files/'.rawurlencode($id).'?supportsAllDrives=true', ['trashed' => true])));

            return;
        }
        $this->guard($this->call(fn () => $this->api($s)->delete($this->msItem($s, $id))));
    }

    // --- Token ---------------------------------------------------------------

    /** Token de acesso válido, renovando quando estiver para vencer. */
    public function accessToken(CloudStorage $s): string
    {
        if (filled($s->access_token) && $s->token_expires_at && $s->token_expires_at->isAfter(now()->addSeconds(90))) {
            return $s->access_token;
        }
        if (blank($s->refresh_token)) {
            throw new CloudException("O {$s->driver_label} não está conectado. Um administrador precisa conectar em Integrações › Arquivos na nuvem.");
        }

        try {
            $this->storeTokens($s, $this->tokenRequest($s, ['grant_type' => 'refresh_token', 'refresh_token' => $s->refresh_token]));
        } catch (CloudException $e) {
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                // Autorização revogada, senha trocada ou app em modo de teste no Google (expira em 7 dias).
                $s->forceFill(['refresh_token' => null, 'access_token' => null, 'last_error' => $e->getMessage()])->save();
                throw new CloudException("A autorização do {$s->driver_label} expirou ou foi revogada. Um administrador precisa conectar de novo em Integrações › Arquivos na nuvem.", 0, $e);
            }
            throw $e;
        }

        return (string) $s->access_token;
    }

    private function tokenRequest(CloudStorage $s, array $params): array
    {
        $url = $s->driver === 'google' ? self::GOOGLE_TOKEN : $this->msBase($s).'/token';
        $params += ['client_id' => $s->client_id, 'client_secret' => $s->client_secret];
        if ($s->driver === 'onedrive') {
            $params['scope'] = self::MS_SCOPES;
        }
        $r = $this->call(fn () => $this->http()->asForm()->post($url, $params));
        if (! $r->successful() || ! $r->json('access_token')) {
            $error = $r->json('error') ?? 'erro';
            $detail = $r->json('error_description') ?? Str::limit($r->body(), 300);
            $hint = match (true) {
                $error === 'invalid_client' || str_contains((string) $detail, 'AADSTS7000215') => ' Confira o ID do cliente e a chave secreta.',
                $error === 'redirect_uri_mismatch' || str_contains((string) $detail, 'AADSTS50011') => ' A URL de retorno cadastrada no app não é igual à desta tela.',
                default => '',
            };
            throw new CloudException("Falha no login ({$error}): {$detail}.{$hint}");
        }

        return $r->json();
    }

    private function storeTokens(CloudStorage $s, array $token): void
    {
        $s->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $s->refresh_token,
            'token_expires_at' => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();
    }

    // --- Apoio ---------------------------------------------------------------

    private function api(CloudStorage $s, int $timeout = 30): PendingRequest
    {
        return $this->http($timeout)->withToken($this->accessToken($s))->asJson();
    }

    private function http(int $timeout = 30): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)->withOptions(['verify' => LlmClient::caBundle()])->acceptJson();
    }

    private function call(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new CloudException('Não foi possível conectar à nuvem: '.Str::limit($e->getMessage(), 300), 0, $e);
        }
    }

    private function guard(Response $r): Response
    {
        if ($r->successful()) {
            return $r;
        }
        $detail = $r->json('error.message') ?? $r->json('error_description') ?? Str::limit($r->body(), 300);
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $hint = match ($r->status()) {
            401 => ' A autorização pode ter expirado: conecte de novo.',
            403 => ' A conta conectada não tem permissão para esta pasta (ou a API do Google Drive não foi ativada no projeto).',
            404 => ' A pasta ou o arquivo não existe mais.',
            413 => ' O arquivo é grande demais.',
            429 => ' Muitas requisições; tente de novo em instantes.',
            default => '',
        };
        $e = new CloudException("A nuvem respondeu HTTP {$r->status()}: {$detail}.{$hint}");
        $e->notFound = $r->status() === 404;

        throw $e;
    }

    private function googleScope(CloudStorage $s): string
    {
        return $s->access === 'app' ? 'https://www.googleapis.com/auth/drive.file' : 'https://www.googleapis.com/auth/drive';
    }

    private function googleListParams(string $q, int $limit): array
    {
        return [
            'q' => $q,
            'fields' => 'files('.self::GOOGLE_FIELDS.')',
            'pageSize' => $limit,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
    }

    /** Escapa texto dentro de uma consulta do Google Drive. */
    private function gq(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function googleItem(array $f): array
    {
        $folder = ($f['mimeType'] ?? '') === self::GOOGLE_FOLDER;

        return [
            'id' => (string) $f['id'],
            'name' => (string) ($f['name'] ?? ''),
            'folder' => $folder,
            'size' => isset($f['size']) ? (int) $f['size'] : null,
            'modified' => isset($f['modifiedTime']) ? Carbon::parse($f['modifiedTime'])->setTimezone(config('app.timezone')) : null,
            'url' => $f['webViewLink'] ?? ($folder ? 'https://drive.google.com/drive/folders/'.$f['id'] : 'https://drive.google.com/file/d/'.$f['id'].'/view'),
            'mime' => $f['mimeType'] ?? null,
            'parents' => $f['parents'] ?? [],
        ];
    }

    private function msBase(CloudStorage $s): string
    {
        return self::MS_LOGIN.'/'.rawurlencode($s->tenant ?: 'common').'/oauth2/v2.0';
    }

    private function msItem(CloudStorage $s, ?string $id): string
    {
        $drive = $s->drive_id ? '/drives/'.rawurlencode($s->drive_id) : '/me/drive';

        return self::GRAPH.$drive.'/items/'.($id === null ? 'root' : rawurlencode($id));
    }

    private function msItemArray(array $i): array
    {
        return [
            'id' => (string) $i['id'],
            'name' => (string) ($i['name'] ?? ''),
            'folder' => isset($i['folder']),
            'size' => isset($i['folder']) ? null : (isset($i['size']) ? (int) $i['size'] : null),
            'modified' => isset($i['lastModifiedDateTime']) ? Carbon::parse($i['lastModifiedDateTime'])->setTimezone(config('app.timezone')) : null,
            'url' => $i['webUrl'] ?? null,
            'mime' => $i['file']['mimeType'] ?? null,
            'parents' => isset($i['parentReference']['id']) ? [$i['parentReference']['id']] : [],
        ];
    }

    public function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, $value < 10 ? 1 : 0, ',', '.')).' '.$units[$i];
    }
}
