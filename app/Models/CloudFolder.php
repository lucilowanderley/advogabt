<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pasta da nuvem de um processo ou de um contato. */
class CloudFolder extends Model
{
    public const OWNERS = ['processo' => LegalCase::class, 'contato' => Contact::class];

    protected $fillable = ['cloud_storage_id', 'owner_type', 'owner_id', 'remote_id', 'name', 'web_url', 'linked'];

    protected function casts(): array
    {
        return ['linked' => 'boolean'];
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(CloudStorage::class, 'cloud_storage_id');
    }
}
