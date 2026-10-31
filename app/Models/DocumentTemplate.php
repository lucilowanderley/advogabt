<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modelo de documento com variáveis no formato {{cliente.nome}}. */
class DocumentTemplate extends Model
{
    protected $fillable = ['name', 'category', 'content', 'user_id'];

    /** Variáveis disponíveis e sua descrição (exibidas no editor). */
    public const VARIABLES = [
        'cliente.nome' => 'Nome do cliente',
        'cliente.documento' => 'CPF/CNPJ do cliente',
        'cliente.rg' => 'RG do cliente',
        'cliente.nacionalidade' => 'Nacionalidade',
        'cliente.estado_civil' => 'Estado civil',
        'cliente.profissao' => 'Profissão',
        'cliente.endereco' => 'Endereço completo',
        'cliente.email' => 'E-mail do cliente',
        'cliente.telefone' => 'Telefone do cliente',
        'processo.numero' => 'Número do processo',
        'processo.titulo' => 'Título / assunto do processo',
        'processo.parte_contraria' => 'Parte contrária',
        'processo.vara' => 'Vara',
        'processo.comarca' => 'Comarca',
        'processo.tribunal' => 'Tribunal',
        'processo.valor_causa' => 'Valor da causa',
        'advogado.nome' => 'Nome do advogado (usuário logado)',
        'advogado.oab' => 'OAB do advogado',
        'escritorio.nome' => 'Nome do escritório',
        'data.hoje' => 'Data de hoje',
        'data.extenso' => 'Data de hoje por extenso',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Substitui as variáveis pelos dados do cliente/processo/advogado. */
    public function render(?Contact $contact, ?LegalCase $case, ?User $lawyer): string
    {
        $contact ??= $case?->client;
        $address = $contact ? collect([$contact->address, $contact->city, $contact->state, $contact->zip_code ? 'CEP '.$contact->zip_code : null])->filter()->implode(', ') : '';

        $values = [
            'cliente.nome' => $contact?->name,
            'cliente.documento' => $contact?->document,
            'cliente.rg' => $contact?->rg,
            'cliente.nacionalidade' => $contact?->nationality,
            'cliente.estado_civil' => $contact?->marital_status,
            'cliente.profissao' => $contact?->profession,
            'cliente.endereco' => $address,
            'cliente.email' => $contact?->email,
            'cliente.telefone' => $contact?->phone,
            'processo.numero' => $case?->number,
            'processo.titulo' => $case?->title,
            'processo.parte_contraria' => $case?->opposing_party,
            'processo.vara' => $case?->court_division,
            'processo.comarca' => $case?->district,
            'processo.tribunal' => $case?->court,
            'processo.valor_causa' => $case?->claim_value ? Format::money($case->claim_value) : null,
            'advogado.nome' => $lawyer?->name,
            'advogado.oab' => $lawyer?->oab,
            'escritorio.nome' => config('app.name'),
            'data.hoje' => now()->format('d/m/Y'),
            'data.extenso' => now()->translatedFormat('j \d\e F \d\e Y'),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/', function ($m) use ($values) {
            if (! array_key_exists($m[1], $values)) {
                return $m[0];
            }

            return filled($values[$m[1]]) ? (string) $values[$m[1]] : '________________';
        }, $this->content);
    }
}
