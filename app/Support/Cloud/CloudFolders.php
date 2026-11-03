<?php

namespace App\Support\Cloud;

use App\Models\CloudFolder;
use App\Models\CloudStorage;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Organiza as pastas do escritório na nuvem:
 *
 *   ADVOGABT/
 *     Clientes/<cliente>/<processo>/(Petições, Documentos do cliente, ...)   layout "cliente"
 *     Processos/<processo>/                                                  processos sem cliente ou layout "separadas"
 *
 * Pastas que já existem com o mesmo nome são aproveitadas.
 */
class CloudFolders
{
    public function __construct(private CloudClient $client) {}

    public function client(): CloudClient
    {
        return $this->client;
    }

    /** Cria (ou aproveita) a pasta principal. Com $link, usa uma pasta já existente. */
    public function setupRoot(CloudStorage $s, ?string $link = null): array
    {
        $item = filled($link)
            ? $this->client->resolveLink($s, $link)
            : $this->client->ensureFolder($s, self::safeName($s->root_name ?: 'ADVOGABT'), null);

        $s->forceFill([
            'root_folder_id' => $item['id'],
            'root_folder_url' => $item['url'],
            'root_name' => $item['name'],
            'folder_cache' => null,
        ])->save();

        return $item;
    }

    public static function owner(string $type, int $id): LegalCase|Contact
    {
        abort_unless(isset(CloudFolder::OWNERS[$type]), 404);

        return CloudFolder::OWNERS[$type]::findOrFail($id);
    }

    public static function ownerType(Model $owner): string
    {
        return $owner instanceof LegalCase ? 'processo' : 'contato';
    }

    public function mapping(CloudStorage $s, LegalCase|Contact $owner): ?CloudFolder
    {
        return CloudFolder::where('cloud_storage_id', $s->id)
            ->where('owner_type', self::ownerType($owner))
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    /** Pasta do processo/contato; cria quando ainda não existe. */
    public function ensure(CloudStorage $s, LegalCase|Contact $owner, bool $retry = true): CloudFolder
    {
        if ($folder = $this->mapping($s, $owner)) {
            return $folder;
        }

        try {
            $parent = $this->parentId($s, $owner);
            $name = $this->folderName($owner);
            $item = $this->client->findFolder($s, $name, $parent);
            $created = ! $item;
            $item ??= $this->client->createFolder($s, $name, $parent);
        } catch (CloudException $e) {
            if (! $e->notFound || ! $retry) {
                throw $e;
            }
            // A pasta-mãe sumiu (excluída na nuvem): esquece os ids guardados e tenta de novo.
            $s->forceFill(['folder_cache' => null])->save();
            if ($owner instanceof LegalCase && $owner->client) {
                $this->mapping($s, $owner->client)?->delete();
            }

            return $this->ensure($s, $owner, false);
        }

        if ($created && $owner instanceof LegalCase) {
            foreach ($s->subfolderNames() as $sub) {
                $this->client->createFolder($s, self::safeName($sub), $item['id']);
            }
        }

        return $this->remember($s, $owner, $item, false);
    }

    /** Vincula uma pasta que já existe na nuvem (link ou id). */
    public function link(CloudStorage $s, LegalCase|Contact $owner, string $link): CloudFolder
    {
        return $this->remember($s, $owner, $this->client->resolveLink($s, $link), true);
    }

    /**
     * Conteúdo da pasta. Devolve null (e esquece o vínculo) se a pasta foi excluída na nuvem.
     *
     * @return array{folder: array, items: list<array>}|null
     */
    public function contents(CloudStorage $s, CloudFolder $folder): ?array
    {
        try {
            $info = $this->client->item($s, $folder->remote_id);
        } catch (CloudException $e) {
            if ($e->notFound) {
                $folder->delete();

                return null;
            }
            throw $e;
        }
        if ($info['url'] && ($info['url'] !== $folder->web_url || $info['name'] !== $folder->name)) {
            $folder->update(['web_url' => $info['url'], 'name' => $info['name']]);
        }

        return ['folder' => $info, 'items' => $this->client->children($s, $folder->remote_id)];
    }

    /** Cria as pastas que faltam (clientes e processos). @return array{contatos: int, processos: int, erros: list<string>} */
    public function createMissing(CloudStorage $s, int $limit = 40): array
    {
        $result = ['contatos' => 0, 'processos' => 0, 'erros' => []];
        $done = fn (string $type) => CloudFolder::where('cloud_storage_id', $s->id)->where('owner_type', $type)->select('owner_id');

        $contacts = Contact::where('category', 'cliente')->whereNotIn('id', $done('contato'))->orderBy('id')->limit($limit)->get();
        foreach ($contacts as $c) {
            $this->attempt($s, $c, $result, 'contatos');
        }
        $left = $limit - $contacts->count();
        if ($left > 0) {
            foreach (LegalCase::with('client')->whereNotIn('id', $done('processo'))->orderBy('id')->limit($left)->get() as $case) {
                $this->attempt($s, $case, $result, 'processos');
            }
        }

        return $result;
    }

    /** Quantos clientes e processos ainda estão sem pasta. */
    public function missingCount(CloudStorage $s): int
    {
        $done = fn (string $type) => CloudFolder::where('cloud_storage_id', $s->id)->where('owner_type', $type)->select('owner_id');

        return Contact::where('category', 'cliente')->whereNotIn('id', $done('contato'))->count()
            + LegalCase::whereNotIn('id', $done('processo'))->count();
    }

    /** Copia um arquivo recebido pelo WhatsApp para a pasta do cliente (subpasta "WhatsApp"). */
    public function copyWhatsapp(CloudStorage $s, WhatsappMessage $m, Contact $contact): array
    {
        $folder = $this->ensure($s, $contact);
        $target = $this->client->ensureFolder($s, 'WhatsApp', $folder->remote_id);
        $name = self::safeName($m->created_at->format('Y-m-d H\hi').' - '.($m->media_name ?: basename((string) $m->media_path)));
        $stream = Storage::disk('local')->readStream($m->media_path);
        try {
            return $this->client->upload($s, $target['id'], $name, $stream, $m->media_mime);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function folderName(LegalCase|Contact $owner): string
    {
        if ($owner instanceof Contact) {
            $twin = Contact::where('name', $owner->name)->where('id', '!=', $owner->id)->exists();

            return self::safeName($owner->name.($twin ? ' - '.($owner->document ?: '#'.$owner->id) : ''));
        }

        $name = $owner->number ? "{$owner->number} - {$owner->title}" : $owner->title;
        $twin = ! $owner->number && LegalCase::where('title', $owner->title)->whereNull('number')->where('id', '!=', $owner->id)->exists();

        return self::safeName($name.($twin ? ' #'.$owner->id : ''));
    }

    /** Nome aceito pelo Google Drive e pelo OneDrive. */
    public static function safeName(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), ' .');

        return Str::limit($name, 150, '') ?: 'Sem nome';
    }

    private function parentId(CloudStorage $s, LegalCase|Contact $owner): string
    {
        if ($owner instanceof LegalCase && $s->case_layout === 'cliente' && $owner->client) {
            return $this->ensure($s, $owner->client)->remote_id;
        }

        return $this->section($s, $owner instanceof LegalCase ? 'Processos' : 'Clientes');
    }

    /** Pasta "Clientes" ou "Processos" dentro da principal (id guardado). */
    private function section(CloudStorage $s, string $name): string
    {
        $cache = $s->folder_cache ?? [];
        if (empty($cache[$name])) {
            if (blank($s->root_folder_id)) {
                throw new CloudException('A pasta principal ainda não foi criada. Conecte a nuvem em Integrações › Arquivos na nuvem.');
            }
            $cache[$name] = $this->client->ensureFolder($s, $name, $s->root_folder_id)['id'];
            $s->forceFill(['folder_cache' => $cache])->save();
        }

        return $cache[$name];
    }

    private function remember(CloudStorage $s, LegalCase|Contact $owner, array $item, bool $linked): CloudFolder
    {
        return CloudFolder::updateOrCreate(
            ['cloud_storage_id' => $s->id, 'owner_type' => self::ownerType($owner), 'owner_id' => $owner->getKey()],
            ['remote_id' => $item['id'], 'name' => $item['name'], 'web_url' => $item['url'], 'linked' => $linked],
        );
    }

    private function attempt(CloudStorage $s, LegalCase|Contact $owner, array &$result, string $key): void
    {
        try {
            $this->ensure($s, $owner);
            $result[$key]++;
        } catch (CloudException $e) {
            $result['erros'][] = ($owner->title ?? $owner->name).': '.$e->getMessage();
        }
    }
}
