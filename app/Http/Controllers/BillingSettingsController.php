<?php

namespace App\Http\Controllers;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\FiscalInvoice;
use App\Support\Billing\AsaasDriver;
use App\Support\Billing\Billing;
use App\Support\Billing\BillingException;
use App\Support\Billing\InterDriver;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Integrações › Cobrança e notas fiscais: contas no Asaas e no Banco Inter e configuração da NFS-e. */
class BillingSettingsController extends Controller
{
    public function settings(Request $request): View
    {
        $saved = BillingProvider::all()->keyBy('driver');
        $providers = [];
        foreach (array_keys(BillingProvider::DRIVERS) as $driver) {
            $providers[$driver] = $saved[$driver]
                ?? new BillingProvider(['driver' => $driver, 'environment' => 'sandbox', 'active' => true, 'fine_percent' => 2, 'interest_percent' => 1, 'notify_customer' => true]);
        }

        $current = $request->query('conta');
        if (! isset($providers[$current])) {
            $current = collect($providers)->filter->exists->sortByDesc('is_default')->keys()->first() ?? 'asaas';
        }

        return view('billing.settings', [
            'providers' => $providers,
            'current' => $current,
            'open' => BillingCharge::open()->count(),
            'paidMonth' => BillingCharge::where('status', 'pago')->where('paid_at', '>=', now()->startOfMonth())->count(),
            'invoicesMonth' => FiscalInvoice::where('status', 'emitida')->where('issued_at', '>=', now()->startOfMonth())->count(),
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        abort_unless(isset(BillingProvider::DRIVERS[$driver]), 404);
        $p = BillingProvider::forDriver($driver) ?? new BillingProvider(['driver' => $driver]);
        $fields = BillingProvider::DRIVERS[$driver]['fields'];
        $request->merge([
            'fine_percent' => Format::parseMoney($request->input('fine_percent')) ?? 0,
            'interest_percent' => Format::parseMoney($request->input('interest_percent')) ?? 0,
        ]);
        $environment = in_array($request->input('environment'), array_keys(BillingProvider::ENVIRONMENTS), true) ? $request->input('environment') : 'sandbox';

        $rules = [
            'environment' => ['required', Rule::in(array_keys(BillingProvider::ENVIRONMENTS))],
            'fine_percent' => ['numeric', 'min:0', 'max:2'],
            'interest_percent' => ['numeric', 'min:0', 'max:10'],
        ];
        $names = ['fine_percent' => 'multa', 'interest_percent' => 'juros'];
        foreach ($fields as $name => $f) {
            $needed = ($f['required'] ?? false) === true || ($f['required'] ?? false) === $environment;
            $hasValue = filled($p->credential($name));
            if (in_array($f['type'], ['cert', 'key'], true)) {
                $rules[$name.'_file'] = [$needed && ! $hasValue ? 'required' : 'nullable', 'nullable', 'file', 'max:64'];
                $names[$name.'_file'] = $f['label'];
            } else {
                $rules[$name] = [$needed && ($f['type'] === 'text' || ! $hasValue) ? 'required' : 'nullable', 'nullable', 'string', 'max:500'];
                $names[$name] = $f['label'];
            }
        }
        $data = $request->validate($rules, [
            'fine_percent.max' => 'A multa por atraso não pode passar de 2% (Código de Defesa do Consumidor, art. 52, § 1º).',
        ], $names);

        $files = [];
        foreach ($fields as $name => $f) {
            if (in_array($f['type'], ['cert', 'key'], true)) {
                $pem = $this->pem($request, $name.'_file', $f['type'] === 'cert' ? 'CERTIFICATE' : 'PRIVATE KEY');
                if ($pem === false) {
                    return back()->withInput()->with('error', 'Arquivo inválido em "'.$f['label'].'". Envie o '.($f['type'] === 'cert'
                        ? 'certificado (.crt, começa com "-----BEGIN CERTIFICATE-----")' : 'arquivo da chave (.key, começa com "-----BEGIN PRIVATE KEY-----")').'.');
                }
                $files[$name] = $pem;
            }
        }

        $before = $p->exists ? [$p->environment, $p->getAttributes()] : null;
        $p->fill([
            'environment' => $data['environment'],
            'fine_percent' => $data['fine_percent'],
            'interest_percent' => $data['interest_percent'],
            'notify_customer' => $request->boolean('notify_customer'),
            'active' => $request->boolean('active'),
            'is_default' => $request->boolean('is_default'),
        ]);
        $settings = $p->settings ?? [];
        foreach ($fields as $name => $f) {
            $value = in_array($f['type'], ['cert', 'key'], true) ? ($files[$name] ?? null) : (isset($data[$name]) ? trim((string) $data[$name]) : null);
            if ($f['type'] !== 'text' && blank($value)) {
                continue; // segredo/arquivo em branco: mantém o atual
            }
            if (in_array($name, BillingProvider::COLUMNS, true)) {
                $p->{$name} = filled($value) ? $value : null;
            } else {
                $settings[$name] = filled($value) ? $value : null;
            }
        }
        $p->settings = $settings ?: null;
        if ($before && ($before[0] !== $p->environment || $p->isDirty(array_merge(BillingProvider::COLUMNS, ['settings'])))) {
            $p->forceFill(['last_test_at' => null, 'last_test_ok' => null, 'last_error' => null]);
        }
        $p->save();
        if ($p->is_default) {
            BillingProvider::where('id', '!=', $p->id)->update(['is_default' => false]);
        }

        return redirect()->route('billing.settings', ['conta' => $driver])->with('success', $p->label.' salvo. Use "Testar conexão" para conferir.');
    }

    public function testProvider(string $driver): RedirectResponse
    {
        $p = BillingProvider::forDriver($driver);
        if (! $p?->isConfigured()) {
            return back()->with('error', 'Salve as credenciais antes de testar.');
        }
        try {
            $info = Billing::driver($driver)->test($p);
            $p->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'last_error' => null])->save();

            return back()->with('success', $info);
        } catch (BillingException $e) {
            $p->forceFill(['last_test_at' => now(), 'last_test_ok' => false, 'last_error' => $e->getMessage()])->save();

            return back()->with('error', $e->getMessage());
        }
    }

    public function disconnect(string $driver): RedirectResponse
    {
        $p = BillingProvider::forDriver($driver);
        abort_unless($p, 404);
        if (BillingCharge::open()->where('provider', $driver)->exists() || ($driver === 'asaas' && FiscalInvoice::where('status', 'processando')->exists())) {
            $p->forceFill(['active' => false])->save();

            return back()->with('success', $p->label.' desativado para novas cobranças. As cobranças em aberto continuam sendo acompanhadas.');
        }
        $p->delete();

        return back()->with('success', $p->label.' desconectado e credenciais apagadas.');
    }

    /** Cadastra o endereço de aviso no Banco Inter (o Asaas é configurado no painel dele). */
    public function registerWebhook(): RedirectResponse
    {
        $p = BillingProvider::forDriver('inter');
        if (! $p?->isConfigured()) {
            return back()->with('error', 'Configure o Banco Inter antes.');
        }
        if (Billing::isLocalUrl($p->webhook_url) || ! str_starts_with($p->webhook_url, 'https://')) {
            return back()->with('error', 'O Banco Inter só aceita aviso em endereço público com HTTPS. No Laragon a rotina consulta os pagamentos sozinha.');
        }
        try {
            app(InterDriver::class)->registerWebhook($p);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Endereço de aviso cadastrado no Banco Inter.');
    }

    // --- NFS-e -------------------------------------------------------------------

    public function saveNfse(Request $request): RedirectResponse
    {
        $p = BillingProvider::forDriver('asaas');
        if (! $p?->isConfigured()) {
            return back()->with('error', 'A NFS-e é emitida pelo Asaas: salve a chave de API do Asaas primeiro (mesmo que as cobranças sejam pelo Banco Inter).');
        }
        $request->merge(['iss' => Format::parseMoney($request->input('iss'))]);
        $data = $request->validate([
            'service' => ['nullable', 'string', 'max:600'],
            'service_code' => ['nullable', 'string', 'max:30'],
            'service_name' => ['nullable', 'string', 'max:250'],
            'iss' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'description' => ['required', 'string', 'max:1000'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ], [], ['iss' => 'alíquota do ISS', 'description' => 'descrição do serviço', 'service_code' => 'código do serviço']);

        $settings = array_merge(BillingProvider::NFSE_DEFAULTS, $p->settings ?? []);
        // Serviço escolhido na lista do Asaas: "id|descrição|iss".
        if (filled($data['service'] ?? null)) {
            [$id, $name, $iss] = array_pad(explode('|', $data['service'], 3), 3, null);
            $settings['service_id'] = $id ?: null;
            $settings['service_name'] = $name ?: $settings['service_name'];
            $settings['service_code'] = null;
            if ($iss !== null && $iss !== '' && $data['iss'] === null) {
                $data['iss'] = (float) $iss;
            }
        } elseif (filled($data['service_code'] ?? null)) {
            $settings['service_id'] = null;
            $settings['service_code'] = trim($data['service_code']);
            $settings['service_name'] = $data['service_name'] ?? null;
        }
        $settings['iss'] = $data['iss'];
        $settings['description'] = trim($data['description']);
        $settings['observations'] = $data['observations'] ?? null;
        $settings['retain_iss'] = $request->boolean('retain_iss');
        $settings['nfse_enabled'] = $request->boolean('nfse_enabled');
        $settings['nfse_auto'] = $request->boolean('nfse_auto');
        $p->settings = $settings;
        $p->save();

        $msg = $settings['nfse_enabled'] ? 'NFS-e configurada.' : 'Configuração salva (emissão de NFS-e desligada).';
        if ($settings['nfse_enabled'] && ! $settings['service_id'] && ! $settings['service_code']) {
            $msg .= ' Falta escolher o serviço municipal.';
        }

        return redirect()->route('billing.settings')->with('success', $msg);
    }

    /** Busca os serviços municipais da prefeitura do escritório (lista volta na própria tela). */
    public function services(Request $request): RedirectResponse
    {
        $p = BillingProvider::forDriver('asaas');
        if (! $p?->isConfigured()) {
            return back()->with('error', 'Salve a chave de API do Asaas primeiro.');
        }
        $search = trim((string) $request->input('busca', 'advoca'));
        try {
            $services = app(AsaasDriver::class)->municipalServices($p, $search);
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.settings')->with('nfse_services', $services)->with('nfse_search', $search)
            ->with($services ? 'success' : 'error', $services ? count($services).' serviço(s) encontrado(s). Escolha na lista e salve.'
                : 'Nenhum serviço encontrado para "'.$search.'". Tente outra palavra (ex.: "jurídic", "17.14") ou informe o código manualmente.');
    }

    public function testNfse(): RedirectResponse
    {
        $p = BillingProvider::forDriver('asaas');
        if (! $p?->isConfigured()) {
            return back()->with('error', 'Salve a chave de API do Asaas primeiro.');
        }
        try {
            return back()->with('success', app(AsaasDriver::class)->fiscalInfo($p));
        } catch (BillingException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Conteúdo PEM de um arquivo enviado. null = nada enviado; false = arquivo inválido. */
    private function pem(Request $request, string $field, string $label): string|null|false
    {
        if (! $request->hasFile($field)) {
            return null;
        }
        $content = trim((string) file_get_contents($request->file($field)->getRealPath()));

        return preg_match('/-----BEGIN [A-Z ]*'.$label.'-----/', $content) ? $content."\n" : false;
    }
}
