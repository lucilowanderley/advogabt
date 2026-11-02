<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Google Drive ou OneDrive do escritório, onde ficam as pastas dos processos e clientes. */
class CloudStorage extends Model
{
    public const DRIVERS = [
        'google' => [
            'label' => 'Google Drive',
            'note' => 'Google Drive pessoal ou do Google Workspace do escritório (inclusive drives compartilhados).',
            'console' => 'https://console.cloud.google.com/apis/credentials',
        ],
        'onedrive' => [
            'label' => 'OneDrive',
            'note' => 'OneDrive pessoal ou OneDrive for Business do Microsoft 365 do escritório.',
            'console' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        ],
    ];

    public const ACCESS = [
        'completo' => 'Todo o Drive (vê também arquivos colocados na pasta direto pelo Google Drive)',
        'app' => 'Só o que o ADVOGABT criar (permissão mais restrita; arquivos colocados direto no Drive não aparecem)',
    ];

    public const LAYOUTS = [
        'cliente' => 'Processos dentro da pasta do cliente (Clientes › Maria Souza › 0001234-56.2026 - Ação de cobrança)',
        'separadas' => 'Pastas separadas (Clientes › Maria Souza e Processos › 0001234-56.2026 - Ação de cobrança)',
    ];

    public const DEFAULT_SUBFOLDERS = "Petições\nDocumentos do cliente\nDecisões e publicações\nProcuração e contrato";

    protected $fillable = [
        'driver', 'client_id', 'client_secret', 'tenant', 'access', 'redirect_uri', 'root_name', 'case_layout',
        'subfolders', 'auto_create', 'copy_whatsapp',
    ];

    protected $hidden = ['client_secret', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'folder_cache' => 'array',
            'auto_create' => 'boolean',
            'copy_whatsapp' => 'boolean',
        ];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(CloudFolder::class);
    }

    /** O armazenamento configurado no escritório (há no máximo um). */
    public static function current(): ?self
    {
        return static::orderBy('id')->first();
    }

    /** O armazenamento, se estiver conectado e pronto para uso. */
    public static function connected(): ?self
    {
        $storage = static::current();

        return $storage?->isConnected() ? $storage : null;
    }

    public function isConnected(): bool
    {
        return filled($this->refresh_token) && filled($this->root_folder_id);
    }

    public function getDriverLabelAttribute(): string
    {
        return self::DRIVERS[$this->driver]['label'] ?? $this->driver;
    }

    /** @return list<string> */
    public function subfolderNames(): array
    {
        return collect(preg_split('/\R/', (string) $this->subfolders))
            ->map(fn ($n) => trim($n))->filter()->unique()->take(15)->values()->all();
    }

    /** Endereço de retorno padrão do login (redirect URI) para este endereço do sistema. */
    public static function suggestedRedirectUri(): string
    {
        $uri = route('cloud.callback');
        $host = (string) parse_url($uri, PHP_URL_HOST);
        $local = ! str_contains($host, '.') || preg_match('/\.(test|local|localhost|lan|home|internal)$/', $host);
        if (str_starts_with($uri, 'https://') && ! $local) {
            return $uri;
        }
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $uri;
        }

        // Google e Microsoft só aceitam http em "localhost". No Laragon a pasta do projeto responde em
        // http://localhost/<pasta>/public, então o retorno passa por lá.
        return 'http://localhost/'.basename(base_path()).'/public/integracoes/arquivos/retorno';
    }
}
