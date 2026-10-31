<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'status', 'priority', 'group', 'due_at', 'is_deadline', 'points',
        'completed_at', 'legal_case_id', 'contact_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_deadline' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->status === 'concluida' && ! $task->completed_at) {
                $task->completed_at = now();
            }
            if ($task->status !== 'concluida') {
                $task->completed_at = null;
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'concluida');
    }

    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $userId ? $query->whereHas('users', fn (Builder $q) => $q->whereKey($userId)) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('group', 'like', "%{$term}%");
        });
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'concluida' && $this->due_at && $this->due_at->lt(today());
    }

    /** Subtítulo exibido no card: processo, cliente ou grupo. */
    public function getContextLabelAttribute(): ?string
    {
        return $this->legalCase?->title ?? $this->contact?->name;
    }
}
