<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Andamento processual. */
class CaseMovement extends Model
{
    protected $fillable = ['legal_case_id', 'user_id', 'date', 'description'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
