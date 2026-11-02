<?php

namespace Tests\Feature;

use App\Models\CloudFolder;
use App\Models\CloudStorage;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Support\Cloud\CloudEvents;
use App\Support\Cloud\CloudFolders;
use App\Support\Integrations\Webhooks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** Google Drive de mentira: id => [name, parent, folder, trashed, size] */
    private array $drive = [];

    private int $tokenCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::flush();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', 'admin')->first();
        $this->withoutDefer();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                $this->tokenCalls++;

                return Http::response(['access_token' => 'at-'.$this->tokenCalls, 'refresh_token' => $r['grant_type'] === 'authorization_code' ? 'rt-1' : null, 'expires_in' => 3600]);
            }
            if (str_starts_with($url, 'https://oauth2.googleapis.com/revoke')) {
                return Http::response([]);
            }
            if ($path === '/drive/v3/about') {
                return Http::response(['user' => ['displayName' => 'Escritório', 'emailAddress' => 'escritorio@gmail.com'], 'storageQuota' => ['limit' => '16106127360', 'usage' => '1073741824']]);
            }
            if ($path === '/drive/v3/files' && $r->method() === 'GET') {
                preg_match("/'([^']+)' in parents/", $query['q'], $p);
                preg_match("/name = '((?:[^'\\\\]|\\\\.)*)'/", $query['q'], $n);
                $files = collect($this->drive)
                    ->filter(fn ($f) => $f['parent'] === $p[1] && ! $f['trashed'])
                    ->filter(fn ($f) => ! $n || $f['name'] === stripslashes($n[1]))
                    ->filter(fn ($f) => ! str_contains($query['q'], 'mimeType') || $f['folder'])
                    ->map(fn ($f, $id) => $this->gFile($id))->values()->all();

                return Http::response(['files' => $files]);
            }
            if ($path === '/drive/v3/files' && $r->method() === 'POST') {
                $id = 'g'.Str::random(8);
                $this->drive[$id] = ['name' => $r['name'], 'parent' => $r['parents'][0], 'folder' => true, 'trashed' => false, 'size' => null];

                return Http::response($this->gFile($id));
            }
            if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
                if (! isset($this->drive[$m[1]])) {
                    return Http::response(['error' => ['message' => 'File not found']], 404);
                }
                if ($r->method() === 'PATCH') {
                    $this->drive[$m[1]]['trashed'] = (bool) $r['trashed'];
                }

                return Http::response($this->gFile($m[1]));
            }
            if ($path === '/upload/drive/v3/files') {
                $sid = 'up'.Str::random(6);
                $this->drive['pending-'.$sid] = ['name' => $r['name'], 'parent' => $r['parents'][0]];

                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session/'.$sid]);
            }
            if (preg_match('#^/upload/session/(.+)$#', $path, $m)) {
                $meta = $this->drive['pending-'.$m[1]];
                unset($this->drive['pending-'.$m[1]]);
                $id = 'f'.Str::random(8);
                $this->drive[$id] = ['name' => $meta['name'], 'parent' => $meta['parent'], 'folder' => false, 'trashed' => false, 'size' => strlen($r->body()), 'body' => $r->body()];

                return Http::response($this->gFile($id));
            }

            return Http::response(['error' => ['message' => "rota não simulada: {$r->method()} {$url}"]], 500);
        });
    }

    private function gFile(string $id): array
    {
        $f = $this->drive[$id];

        return [
            'id' => $id, 'name' => $f['name'], 'parents' => [$f['parent']], 'trashed' => $f['trashed'],
            'mimeType' => $f['folder'] ? 'application/vnd.google-apps.folder' : 'application/pdf',
            'size' => $f['size'] === null ? null : (string) $f['size'], 'modifiedTime' => '2026-11-02T12:00:00Z',
            'webViewLink' => 'https://drive.google.com/'.($f['folder'] ? 'drive/folders/' : 'file/d/').$id,
        ];
    }

    private function names(?string $parent): array
    {
        return collect($this->drive)->filter(fn ($f) => ($f['parent'] ?? null) === $parent && empty($f['trashed']))->pluck('name')->sort()->values()->all();
    }

    private function idOf(string $name): string
    {
        return collect($this->drive)->filter(fn ($f) => ($f['name'] ?? null) === $name)->keys()->first();
    }

    private function connectGoogle(): CloudStorage
    {
        $this->fakeGoogle();
        $this->actingAs($this->admin)->post('/integracoes/arquivos', [
            'driver' => 'google', 'client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'segredo', 'access' => 'completo',
            'redirect_uri' => 'http://localhost/advogabt/public/integracoes/arquivos/retorno', 'root_name' => 'ADVOGABT',
            'case_layout' => 'cliente', 'subfolders' => "Petições\nDecisões", 'auto_create' => '1', 'copy_whatsapp' => '1',
        ])->assertRedirect(route('cloud.settings'));

        $redirect = $this->get('/integracoes/arquivos/conectar')->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('https://www.googleapis.com/auth/drive', $q['scope']);
        $this->assertSame('http://localhost/advogabt/public/integracoes/arquivos/retorno', $q['redirect_uri']);

        // O retorno chega sem sessão (outro endereço): vale o "state".
        auth()->logout();
        $this->get('/integracoes/arquivos/retorno?'.http_build_query(['code' => 'abc', 'state' => $q['state']]))
            ->assertRedirect(route('cloud.settings').'?conectado=1');
        $this->get('/integracoes/arquivos/retorno?'.http_build_query(['code' => 'abc', 'state' => $q['state']]))->assertForbidden(); // uso único

        $storage = CloudStorage::sole();
        $this->assertTrue($storage->isConnected());
        $this->assertSame('escritorio@gmail.com', $storage->account_email);
        $this->assertSame(['ADVOGABT'], $this->names('root'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['code'] === 'abc' && $r['client_secret'] === 'segredo');

        return $storage;
    }

    public function test_google_connection_creates_client_and_case_folders_automatically(): void
    {
        $storage = $this->connectGoogle();
        $this->actingAs($this->admin);

        $client = Contact::create(['name' => 'Maria: Souza', 'category' => 'cliente']);
        $case = LegalCase::create(['title' => 'Ação de cobrança', 'number' => '0001234-56.2026.8.26.0100', 'contact_id' => $client->id]);

        $root = $storage->fresh()->root_folder_id;
        $this->assertSame(['Clientes'], $this->names($root));
        $this->assertSame(['Maria- Souza'], $this->names($this->idOf('Clientes')));
        $this->assertSame(['0001234-56.2026.8.26.0100 - Ação de cobrança'], $this->names($this->idOf('Maria- Souza')));
        $this->assertSame(['Decisões', 'Petições'], $this->names($this->idOf('0001234-56.2026.8.26.0100 - Ação de cobrança')));
        $this->assertSame(2, CloudFolder::count());

        // Leads não ganham pasta; o vínculo some quando o processo é excluído (a pasta fica na nuvem).
        Contact::create(['name' => 'Lead', 'category' => 'outro']);
        $this->assertSame(2, CloudFolder::count());
        $case->delete();
        $this->assertSame(1, CloudFolder::count());
        $this->assertContains('0001234-56.2026.8.26.0100 - Ação de cobrança', $this->names($this->idOf('Maria- Souza')));
    }

    public function test_team_lists_uploads_and_trashes_files_in_case_folder(): void
    {
        $this->connectGoogle();
        $lawyer = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'x', 'role' => 'advogado', 'active' => true]);
        $this->actingAs($lawyer);
        $case = LegalCase::create(['title' => 'Inventário', 'number' => null]);
        $this->get(route('cases.show', $case))->assertOk()->assertSee('data-cloud-panel', false);

        $this->getJson("/arquivos/processo/{$case->id}")->assertOk()->assertJsonPath('html', fn ($h) => str_contains($h, 'Petições') && str_contains($h, 'Enviar'));
        $this->assertSame(['Inventário'], $this->names($this->idOf('Processos')));

        $petitions = $this->idOf('Petições');
        $this->post("/arquivos/processo/{$case->id}", ['files' => [UploadedFile::fake()->createWithContent('inicial.pdf', '%PDF-1.4 inicial')], 'target' => $petitions])
            ->assertSessionHas('success');
        $this->post("/arquivos/processo/{$case->id}", ['files' => [UploadedFile::fake()->createWithContent('procuração.pdf', '%PDF procuração')]])
            ->assertSessionHas('success');
        $this->assertSame(['inicial.pdf'], $this->names($petitions));
        $this->assertSame('%PDF-1.4 inicial', $this->drive[$this->idOf('inicial.pdf')]['body']);

        $html = $this->getJson("/arquivos/processo/{$case->id}")->json('html');
        $this->assertStringContainsString('procuração.pdf', $html);

        // Só arquivos da própria pasta podem ir para a lixeira.
        $this->delete("/arquivos/processo/{$case->id}/itens/".$this->idOf('inicial.pdf'))->assertForbidden();
        $this->delete("/arquivos/processo/{$case->id}/itens/".$this->idOf('procuração.pdf'))->assertSessionHas('success');
        $this->assertTrue($this->drive[$this->idOf('procuração.pdf')]['trashed']);

        // Equipe não mexe na configuração.
        $this->get('/integracoes/arquivos')->assertForbidden();
    }

    public function test_deleted_remote_folder_is_forgotten_and_existing_folder_can_be_linked(): void
    {
        $storage = $this->connectGoogle();
        $this->actingAs($this->admin);
        $storage->update(['auto_create' => false]);
        $client = Contact::create(['name' => 'Pedro', 'category' => 'cliente']);
        $this->assertSame(0, CloudFolder::count());

        $this->getJson("/arquivos/contato/{$client->id}")->assertJsonPath('html', fn ($h) => str_contains($h, 'Criar pasta'));
        $this->post("/arquivos/contato/{$client->id}/pasta")->assertSessionHas('success');
        $folder = CloudFolder::sole();
        $this->drive[$folder->remote_id]['trashed'] = true;

        $this->getJson("/arquivos/contato/{$client->id}")->assertJsonPath('html', fn ($h) => str_contains($h, 'ainda não tem pasta'));
        $this->assertSame(0, CloudFolder::count());

        $this->drive['gExistente1'] = ['name' => 'Pedro (antigo)', 'parent' => 'root', 'folder' => true, 'trashed' => false, 'size' => null];
        $this->post("/arquivos/contato/{$client->id}/vincular", ['link' => 'https://drive.google.com/drive/folders/gExistente1?usp=sharing'])->assertSessionHas('success');
        $this->assertSame('gExistente1', CloudFolder::sole()->remote_id);
        $this->assertTrue(CloudFolder::sole()->linked);
    }

    public function test_missing_folders_are_created_in_bulk_and_token_is_refreshed(): void
    {
        $storage = $this->connectGoogle();
        $this->actingAs($this->admin);
        $storage->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $calls = $this->tokenCalls;

        $this->post('/integracoes/arquivos/pastas')->assertSessionHas('success');
        $this->assertSame($calls + 1, $this->tokenCalls); // renovou uma vez e reutilizou
        $this->assertSame(0, app(CloudFolders::class)->missingCount($storage->fresh()));
        $this->assertGreaterThan(0, CloudFolder::where('owner_type', 'processo')->count());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'rt-1');
    }

    public function test_whatsapp_documents_are_copied_to_client_folder(): void
    {
        $this->connectGoogle();
        $client = Contact::create(['name' => 'Joana', 'category' => 'cliente']);
        $channel = WhatsappChannel::create(['name' => 'WA', 'driver' => 'evolution', 'base_url' => 'https://evo.example.com', 'instance' => 'x', 'active' => true]);
        $conv = $channel->conversations()->create(['phone' => '5521999990000', 'contact_id' => $client->id]);
        Storage::disk('local')->put('whatsapp/1/rg.pdf', '%PDF rg');
        $msg = $conv->messages()->create(['direction' => 'in', 'sender' => 'cliente', 'type' => 'document', 'media_path' => 'whatsapp/1/rg.pdf', 'media_name' => 'rg.pdf', 'media_mime' => 'application/pdf']);

        CloudEvents::whatsappMedia($msg, $client->id);

        $wa = $this->idOf('WhatsApp');
        $this->assertSame($this->idOf('Joana'), $this->drive[$wa]['parent']);
        $files = $this->names($wa);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith(' - rg.pdf', $files[0]);
    }

    public function test_bad_state_and_onedrive_authorization_url(): void
    {
        $this->get('/integracoes/arquivos/retorno?code=x&state=1.errado')->assertForbidden();

        $this->actingAs($this->admin)->post('/integracoes/arquivos', [
            'driver' => 'onedrive', 'client_id' => 'app-id', 'client_secret' => 'valor', 'tenant' => 'organizations',
            'redirect_uri' => 'https://advogabt.exemplo.com.br/integracoes/arquivos/retorno', 'root_name' => 'ADVOGABT', 'case_layout' => 'separadas',
        ])->assertSessionHasNoErrors();
        $redirect = $this->get('/integracoes/arquivos/conectar')->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $redirect);
        $this->assertStringContainsString('Files.ReadWrite.All', urldecode($redirect));

        // Cancelado na Microsoft: volta para a tela com o erro.
        parse_str(parse_url($redirect, PHP_URL_QUERY), $q);
        $this->get('/integracoes/arquivos/retorno?'.http_build_query(['error' => 'access_denied', 'error_description' => 'O usuário cancelou', 'state' => $q['state']]))
            ->assertRedirectContains('erro=');
        $this->get('/integracoes/arquivos?erro=Teste')->assertOk()->assertSee('Teste')->assertSee('Microsoft Entra');
    }

    public function test_onedrive_creates_folders_through_graph(): void
    {
        $storage = CloudStorage::create(['driver' => 'onedrive', 'client_id' => 'a', 'client_secret' => 'b', 'tenant' => 'common', 'redirect_uri' => 'https://x/retorno', 'root_name' => 'ADVOGABT', 'case_layout' => 'separadas', 'auto_create' => true]);
        $storage->forceFill(['refresh_token' => 'rt', 'access_token' => 'at', 'token_expires_at' => now()->addHour(), 'drive_id' => 'D1', 'root_folder_id' => 'ROOT1'])->save();
        $created = [];
        Http::fake(function (Request $r) use (&$created) {
            if (preg_match('#/drives/D1/items/([^/:]+):/([^:]+)$#', parse_url($r->url(), PHP_URL_PATH), $m)) {
                return Http::response(['error' => ['message' => 'itemNotFound']], 404);
            }
            if (preg_match('#/drives/D1/items/([^/]+)/children$#', $r->url(), $m) && $r->method() === 'POST') {
                $created[] = [$m[1], $r['name']];

                return Http::response(['id' => 'I'.count($created), 'name' => $r['name'], 'folder' => ['childCount' => 0], 'webUrl' => 'https://onedrive.live.com/x', 'parentReference' => ['id' => $m[1]]]);
            }

            return Http::response(['error' => ['message' => 'não simulado '.$r->url()]], 500);
        });

        $this->actingAs($this->admin);
        LegalCase::create(['title' => 'Revisional', 'number' => '123']);

        $this->assertSame([['ROOT1', 'Processos'], ['I1', '123 - Revisional']], array_slice($created, 0, 2));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer at'));
    }
}
