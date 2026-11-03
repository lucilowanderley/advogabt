<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Boleto/Pix gerado em uma plataforma de cobrança para um lançamento a receber. */
class BillingCharge extends Model
{
    public const STATUSES = [
        'pendente' => ['label' => 'Aguardando pagamento', 'badge' => 'gold'],
        'vencido' => ['label' => 'Vencido', 'badge' => 'red'],
        'pago' => ['label' => 'Pago', 'badge' => 'green'],
        'cancelado' => ['label' => 'Cancelado', 'badge' => ''],
        'estornado' => ['label' => 'Estornado', 'badge' => 'purple'],
        'erro' => ['label' => 'Falha na emissão', 'badge' => 'red'],
    ];

    /** Situações em que ainda se espera o pagamento. */
    public const OPEN = ['pendente', 'vencido'];

    protected $fillable = [
        'provider', 'method', 'financial_entry_id', 'contact_id', 'user_id', 'description', 'amount', 'due_date', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'date',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'financial_entry_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FiscalInvoice::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status]['label'] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUSES[$this->status]['badge'] ?? '';
    }

    public function getProviderLabelAttribute(): string
    {
        return BillingProvider::label($this->provider);
    }

    public function getMethodLabelAttribute(): string
    {
        return BillingProvider::METHODS[$this->method] ?? $this->method;
    }

    /** Mensagem pronta para mandar ao cliente (WhatsApp, e-mail). */
    public function shareText(): string
    {
        $lines = ['Olá! Segue a cobrança "'.$this->description.'" no valor de R$ '.number_format((float) $this->amount, 2, ',', '.')
            .', com vencimento em '.$this->due_date->format('d/m/Y').'.'];
        if ($this->payment_url) {
            $lines[] = 'Link para pagamento: '.$this->payment_url;
        }
        if ($this->digitable_line) {
            $lines[] = 'Linha digitável do boleto: '.$this->digitable_line;
        }
        if ($this->pix_payload) {
            $lines[] = "Pix copia e cola:\n".$this->pix_payload;
        }

        return implode("\n\n", $lines);
    }

    public function whatsappUrl(): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) ($this->contact?->whatsapp ?: $this->contact?->phone));
        if (strlen($phone) < 10) {
            return null;
        }
        if (strlen($phone) <= 11) {
            $phone = '55'.$phone;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($this->shareText());
    }
}
