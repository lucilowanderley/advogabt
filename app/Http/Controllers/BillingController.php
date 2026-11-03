<?php

namespace App\Http\Controllers;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\FinancialEntry;
use App\Models\FiscalInvoice;
use App\Support\Billing\Billing;
use App\Support\Billing\BillingException;
use App\Support\Billing\Nfse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Cobrança e notas fiscais: boletos/Pix dos lançamentos a receber, baixa automática e NFS-e. */
class BillingController extends Controller
{
    // --- Cobranças -------------------------------------------------------------

    public function index(Request $request): View
    {
        $charges = BillingCharge::with(['contact', 'entry'])
            ->when($request->query('situacao'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%{$t}%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->latest('id')->paginate(25)->withQueryString();

        return view('billing.index', [
            'charges' => $charges,
            'summary' => [
                'aberto' => (float) BillingCharge::where('status', 'pendente')->sum('amount'),
                'vencido' => (float) BillingCharge::where('status', 'vencido')->sum('amount'),
                'recebido' => (float) BillingCharge::where('status', 'pago')->where('paid_at', '>=', now()->startOfMonth())->sum('paid_amount'),
                'notas' => FiscalInvoice::where('status', 'emitida')->where('issued_at', '>=', now()->startOfMonth())->count(),
            ],
            'providers' => BillingProvider::usable(),
            'toCharge' => FinancialEntry::where('type', 'receita')->unpaid()->whereNotNull('contact_id')
                ->whereDoesntHave('charges', fn ($q) => $q->whereIn('status', BillingCharge::OPEN))
                ->whereBetween('due_date', [today()->subDays(30), today()->addDays(45)])
                ->with('contact')->orderBy('due_date')->limit(8)->get(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $entry = FinancialEntry::with(['contact', 'legalCase', 'charges'])->findOrFail($request->query('lancamento'));
        $providers = BillingProvider::usable();
        if ($providers->isEmpty()) {
            return redirect()->route('finance.edit', $entry)->with('error', $request->user()->isAdmin()
                ? 'Configure o Asaas ou o Banco Inter em Integrações › Cobrança e notas fiscais.'
                : 'Peça a um administrador para configurar o Asaas ou o Banco Inter em Integrações.');
        }

        return view('billing.create', [
            'entry' => $entry,
            'providers' => $providers,
            'due' => $entry->due_date->lt(today()) ? today()->addDays(3) : $entry->due_date,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_entry_id' => ['required', 'exists:financial_entries,id'],
            'provider' => ['required', Rule::in(array_keys(BillingProvider::DRIVERS))],
            'method' => ['required', Rule::in(array_keys(BillingProvider::METHODS))],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['due_date' => 'vencimento', 'provider' => 'plataforma', 'method' => 'forma de pagamento']);
        $entry = FinancialEntry::findOrFail($data['financial_entry_id']);

        try {
            $charge = Billing::issue($entry, $data['provider'], $data['method'], Carbon::parse($data['due_date']), $request->user()->id, $data['description'] ?? null);
        } catch (BillingException $e) {
            $failed = $entry->charges()->where('status', 'erro')->latest('id')->first();

            return ($failed && $failed->created_at->gt(now()->subMinute())
                ? redirect()->route('billing.show', $failed)
                : back()->withInput())->with('error', $e->getMessage());
        }

        return redirect()->route('billing.show', $charge)->with('success', 'Cobrança gerada no '.$charge->provider_label.'. Mande o link ou o Pix ao cliente.');
    }

    public function show(BillingCharge $charge): View
    {
        $charge->load(['contact', 'entry.legalCase', 'invoices', 'user']);

        return view('billing.show', [
            'charge' => $charge,
            'provider' => BillingProvider::forDriver($charge->provider),
            'nfse' => BillingProvider::nfse(),
        ]);
    }

    public function refresh(BillingCharge $charge): RedirectResponse
    {
        try {
            $changed = Billing::refresh($charge);
        } catch (BillingException $e) {
            $charge->forceFill(['last_error' => $e->getMessage(), 'last_checked_at' => now()])->save();

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $changed ? 'Situação atualizada: '.$charge->status_label.'.' : 'Sem novidades: '.Str::lower($charge->status_label).'.');
    }

    public function cancel(Request $request, BillingCharge $charge): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() || $charge->user_id === $request->user()->id, 403);
        abort_unless($charge->isOpen() || $charge->status === 'erro', 422);
        try {
            Billing::cancel($charge);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cobrança cancelada. O lançamento continua em aberto no Financeiro.');
    }

    /** PDF do boleto: link público do Asaas ou PDF baixado do Banco Inter. */
    public function pdf(BillingCharge $charge): Response
    {
        if ($charge->slip_url) {
            return redirect()->away($charge->slip_url);
        }
        abort_unless($charge->external_id, 404);
        try {
            $pdf = Billing::driver($charge->provider)->pdf(Billing::provider($charge->provider), $charge);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }
        abort_unless($pdf, 404);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="boleto-'.(Str::slug($charge->description) ?: $charge->id).'.pdf"',
        ]);
    }

    // --- Notas fiscais -----------------------------------------------------------

    public function invoices(Request $request): View
    {
        $invoices = FiscalInvoice::with(['contact', 'entry'])
            ->when($request->query('situacao'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('service_description', 'like', "%{$t}%")
                ->orWhere('number', 'like', "%{$t}%")->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->latest('id')->paginate(25)->withQueryString();

        return view('billing.invoices', [
            'invoices' => $invoices,
            'nfse' => BillingProvider::nfse(),
            'summary' => [
                'mes' => (float) FiscalInvoice::where('status', 'emitida')->where('issued_at', '>=', now()->startOfMonth())->sum('amount'),
                'quantidade' => FiscalInvoice::where('status', 'emitida')->where('issued_at', '>=', now()->startOfMonth())->count(),
                'processando' => FiscalInvoice::where('status', 'processando')->count(),
                'semNota' => FinancialEntry::where('type', 'receita')->paid()->where('paid_at', '>=', now()->startOfMonth())
                    ->whereIn('category', ['honorarios_contratuais', 'honorarios_exito', 'consultoria'])
                    ->whereDoesntHave('invoices', fn ($q) => $q->whereIn('status', ['processando', 'emitida']))->count(),
            ],
        ]);
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_entry_id' => ['required', 'exists:financial_entries,id'],
            'service_description' => ['nullable', 'string', 'max:2000'],
        ]);
        $entry = FinancialEntry::findOrFail($data['financial_entry_id']);
        try {
            $inv = Nfse::issue($entry, null, $request->user()->id, $data['service_description'] ?? null);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'NFS-e enviada ao Asaas (#'.$inv->id.'). A prefeitura costuma autorizar em alguns minutos; o sistema acompanha sozinho.');
    }

    public function refreshInvoice(FiscalInvoice $invoice): RedirectResponse
    {
        try {
            Nfse::refresh($invoice);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($invoice->status === 'erro' ? 'error' : 'success', 'Nota fiscal: '.Str::lower($invoice->status_label).($invoice->number ? ' (nº '.$invoice->number.')' : '').'.'
            .($invoice->status === 'erro' && $invoice->last_error ? ' '.$invoice->last_error : ''));
    }

    public function cancelInvoice(Request $request, FiscalInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Só administradores podem cancelar notas fiscais.');
        try {
            Nfse::cancel($invoice);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $invoice->status === 'cancelada' ? 'Nota cancelada.' : 'Cancelamento pedido à prefeitura. O sistema confirma em alguns minutos.');
    }

    // --- Aviso das plataformas ---------------------------------------------------

    public function webhook(Request $request, string $token): JsonResponse
    {
        $p = BillingProvider::where('webhook_token', $token)->first();
        abort_unless($p, 404);
        $p->forceFill(['last_webhook_at' => now()])->saveQuietly();
        $payload = $request->json()->all() ?: $request->all();
        try {
            $count = Billing::handleWebhook($p, is_array($payload) ? $payload : []);
        } catch (\Throwable $e) {
            report($e);
            $count = 0;
        }

        return response()->json(['ok' => true, 'itens' => $count]);
    }
}
