@extends('layouts.app', ['title' => 'Cobrança e notas fiscais · Integrações'])
@php
    use App\Models\BillingProvider;
    $asaas = $providers['asaas'];
    $ready = collect($providers)->filter(fn ($p) => $p->exists && $p->active && $p->isConfigured());
    $local = \App\Support\Billing\Billing::isLocalUrl(url('/'));
    $services = session('nfse_services', []);
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Cobrança e notas fiscais</div>
<div class="page-head">
    <div>
        <h1>Cobrança e notas fiscais</h1>
        <div class="sub">Contas do escritório no Asaas e nos bancos (Inter, BB, Itaú, Bradesco, Santander, Caixa, Sicoob e Sicredi) para gerar boleto e Pix dos honorários, e a emissão da NFS-e.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('billing.index') }}"><x-icon name="dollar" class="sm" /> Cobranças</a>
    </div>
</div>

@include('integrations._tabs')

<div class="grid grid-3 mb">
    <div class="card kpi">
        <div class="kpi-icon {{ $ready->isNotEmpty() ? 'green' : 'red' }}"><x-icon name="dollar" /></div>
        <div><div class="value" style="font-size: 18px">{{ $ready->isNotEmpty() ? $ready->map->label->implode(' e ') : 'Nenhuma conta' }}</div><div class="label">{{ $ready->isNotEmpty() ? 'Pronta(s) para cobrar' : 'Configure abaixo' }}</div></div>
    </div>
    <div class="card kpi"><div class="kpi-icon"><x-icon name="clock" /></div><div><div class="value">{{ $open }}</div><div class="label">Cobrança(s) aguardando pagamento · {{ $paidMonth }} paga(s) no mês</div></div></div>
    <div class="card kpi"><div class="kpi-icon purple"><x-icon name="file-text" /></div><div><div class="value">{{ $invoicesMonth }}</div><div class="label">NFS-e emitida(s) no mês</div></div></div>
</div>

@if ($local)
    <div class="alert warning small"><x-icon name="alert" /> <div>O sistema está em um endereço local (<code>{{ request()->getHost() }}</code>), então o Asaas e os bancos não conseguem avisar quando o cliente paga. Não tem problema: o sistema <strong>consulta os pagamentos sozinho</strong> pela rotina das integrações (de hora em hora perto do vencimento) e dá a baixa no Financeiro. Na tela da cobrança há o botão <strong>Atualizar situação</strong>. Quando o sistema for publicado com domínio próprio (HTTPS), cadastre o endereço de aviso mostrado em cada conta.</div></div>
@endif

<div class="grid grid-main">
<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="dollar" /> Bancos e plataformas</h3><span class="small muted">Escolha para configurar</span></div>
        <div class="card-body">
            <div class="bank-picker">
                @foreach ($providers as $d => $bp)
                    <a href="{{ route('billing.settings', ['conta' => $d]) }}" class="bank-chip {{ $d === $current ? 'active' : '' }}">
                        <strong>{{ BillingProvider::DRIVERS[$d]['label'] }}</strong>
                        <span class="small {{ $bp->exists && $bp->isConfigured() && $bp->active ? ($bp->last_test_ok === false ? 'text-red' : 'text-green') : 'faint' }}">
                            {{ ! $bp->exists ? 'não configurado' : (! $bp->isConfigured() ? 'incompleto' : (! $bp->active ? 'desativado' : ($bp->last_test_ok === false ? 'falhou no teste' : ($bp->is_default ? 'padrão' : 'pronto')))) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    @php $driver = $current; $p = $providers[$driver]; $info = BillingProvider::DRIVERS[$driver]; @endphp
    <form method="post" action="{{ route('billing.providers.save', $driver) }}" class="card" autocomplete="off" enctype="multipart/form-data">
        @csrf
        <div class="card-head">
            <h3><x-icon name="dollar" /> {{ $info['label'] }} <x-ai-pricing :pricing="$info['pricing']" /></h3>
            @if ($p->exists)
                <span class="badge {{ $p->last_test_ok ? 'green' : ($p->last_test_ok === false ? 'red' : '') }}">{{ $p->last_test_ok ? 'conectado' : ($p->last_test_ok === false ? 'falhou no teste' : ($p->active ? 'não testado' : 'desativado')) }}</span>
            @endif
        </div>
        <div class="card-body form-grid">
            <div class="col-12 small muted">{{ $info['note'] }} <a class="link" target="_blank" rel="noopener" href="{{ $info['site'] }}">Site <x-icon name="external" class="sm" /></a></div>
            <div class="col-12 small"><strong>Onde conseguir:</strong> <span class="muted">{{ $info['help'] }}</span></div>
            <x-field name="environment" label="Ambiente" col="12">
                <select name="environment" id="f-environment">@foreach (BillingProvider::ENVIRONMENTS as $k => $l)<option value="{{ $k }}" @selected(old('environment', $p->environment) === $k)>{{ $l }}</option>@endforeach</select>
            </x-field>
            @foreach ($info['fields'] as $name => $f)
                @php
                    $has = filled($p->credential($name));
                    $col = $f['col'] ?? 6;
                    $req = ($f['required'] ?? false) === true ? ' *' : (($f['required'] ?? false) === 'producao' ? ' (produção)' : '');
                @endphp
                @if (in_array($f['type'], ['cert', 'key'], true))
                    <x-field :name="$name.'_file'" :label="$f['label'].$req" :col="$col" :hint="$has ? 'Já cadastrado. Envie outro só para trocar.' : ($f['hint'] ?? null)">
                        <input type="file" name="{{ $name }}_file" id="f-{{ $name }}_file" accept="{{ $f['type'] === 'cert' ? '.crt,.pem,.cer' : '.key,.pem' }}">
                    </x-field>
                @elseif ($f['type'] === 'secret')
                    <x-field :name="$name" :label="$f['label'].$req" :col="$col" :hint="$has ? 'Já cadastrado. Deixe em branco para manter.' : ($f['hint'] ?? null)">
                        <input type="password" name="{{ $name }}" id="f-{{ $name }}" autocomplete="new-password" maxlength="500" placeholder="{{ $has ? '••••••••' : '' }}">
                    </x-field>
                @else
                    <x-field :name="$name" :label="$f['label'].$req" :col="$col" :hint="$f['hint'] ?? null">
                        <input type="text" name="{{ $name }}" id="f-{{ $name }}" maxlength="500" value="{{ old($name, $p->credential($name) ?? ($f['default'] ?? '')) }}">
                    </x-field>
                @endif
            @endforeach
            <x-field name="fine_percent" label="Multa por atraso (%)" col="6" hint="Máximo 2% (CDC).">
                <input type="text" inputmode="decimal" name="fine_percent" id="f-fine_percent" value="{{ old('fine_percent', number_format((float) $p->fine_percent, 2, ',', '')) }}">
            </x-field>
            <x-field name="interest_percent" label="Juros ao mês (%)" col="6">
                <input type="text" inputmode="decimal" name="interest_percent" id="f-interest_percent" value="{{ old('interest_percent', number_format((float) $p->interest_percent, 2, ',', '')) }}">
            </x-field>
            <div class="field col-12">
                <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $p->exists ? $p->active : true))> <span>Disponível para novas cobranças</span></label>
                <label class="check" style="margin-left: 18px"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $p->is_default))> <span>Conta padrão</span></label>
                @if ($driver === 'asaas')
                    <label class="check" style="margin-left: 18px"><input type="checkbox" name="notify_customer" value="1" @checked(old('notify_customer', $p->exists ? $p->notify_customer : true))> <span>Asaas avisa o cliente por e-mail/SMS</span></label>
                @endif
            </div>
            @if ($p->exists && in_array($driver, ['asaas', 'inter'], true))
                <div class="field col-12">
                    <label>Endereço de aviso (webhook)</label>
                    <div class="copy-field"><code data-copy-source>{{ $p->webhook_url }}</code><button type="button" class="btn btn-sm" data-copy><x-icon name="copy" class="sm" /> Copiar</button></div>
                    <div class="hint">
                        @if ($driver === 'inter')
                            O Inter exige endereço público com HTTPS; com o sistema publicado, use o botão "Cadastrar aviso no Inter".
                        @else
                            No Asaas: Integrações › Webhooks › Adicionar, cole este endereço e marque os eventos de cobrança (e de notas fiscais, se usar NFS-e).
                        @endif
                        Só funciona com o sistema publicado na internet; no Laragon a rotina consulta os pagamentos.
                        @if ($p->last_webhook_at) Último aviso recebido {{ $p->last_webhook_at->diffForHumans() }}.@endif
                    </div>
                </div>
            @elseif ($p->exists)
                <div class="col-12 small muted">A situação dos boletos do {{ $info['label'] }} é consultada pela rotina das integrações (de hora em hora perto do vencimento). Também dá para dar baixa importando o arquivo de retorno CNAB 240 em <a class="link" href="{{ route('billing.index') }}">Cobranças</a>.</div>
            @endif
            @if ($p->last_test_ok === false && $p->last_error)<div class="col-12 small text-red">{{ $p->last_error }}</div>@endif
            <div class="col-12 form-actions">
                @if ($p->exists)
                    <button class="btn btn-ghost" formaction="{{ route('billing.providers.test', $driver) }}" formnovalidate formenctype="application/x-www-form-urlencoded"><x-icon name="plug" class="sm" /> Testar conexão</button>
                    @if ($driver === 'inter' && ! $local)
                        <button class="btn btn-ghost" formaction="{{ route('billing.webhook.register') }}" formnovalidate formenctype="application/x-www-form-urlencoded"><x-icon name="webhook" class="sm" /> Cadastrar aviso no Inter</button>
                    @endif
                @endif
                <span class="spacer"></span>
                <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
            </div>
        </div>
    </form>
    @if ($p->exists)
        <form method="post" action="{{ route('billing.providers.destroy', $driver) }}" data-confirm="Desconectar o {{ $info['label'] }}? As credenciais serão apagadas." style="margin-top: -8px; text-align: right">
            @csrf @method('delete')<button class="btn btn-xs btn-ghost"><x-icon name="trash" class="sm" /> Desconectar {{ $info['label'] }}</button>
        </form>
    @endif

    <form method="post" action="{{ route('billing.nfse.save') }}" class="card" id="nfse">
        @csrf
        <div class="card-head">
            <h3><x-icon name="file-text" /> Nota fiscal de serviço (NFS-e)</h3>
            <span class="badge {{ $asaas->setting('nfse_enabled') ? 'green' : '' }}">{{ $asaas->setting('nfse_enabled') ? 'ligada' : 'desligada' }}</span>
        </div>
        <div class="card-body form-grid">
            <div class="col-12 small muted">
                A NFS-e é emitida pelo <strong>Asaas</strong>, que se conecta à prefeitura do escritório (mesmo que as cobranças sejam pelo Banco Inter ou baixadas à mão).
                Antes, no painel do Asaas, preencha <em>Notas fiscais › Configurações</em>: inscrição municipal, regime tributário e o acesso à prefeitura (certificado digital ou senha).
            </div>
            @if (! $asaas->exists || ! $asaas->isConfigured())
                <div class="col-12 small text-orange">Salve a chave de API do Asaas acima para configurar a NFS-e.</div>
            @else
                <div class="field col-12">
                    <label class="check"><input type="checkbox" name="nfse_enabled" value="1" @checked(old('nfse_enabled', $asaas->setting('nfse_enabled')))> <span>Emitir NFS-e pelo sistema</span></label>
                    <label class="check" style="margin-left: 18px"><input type="checkbox" name="nfse_auto" value="1" @checked(old('nfse_auto', $asaas->setting('nfse_auto')))> <span>Emitir automaticamente quando a cobrança for paga</span></label>
                </div>
                <div class="field col-12">
                    <label>Serviço municipal</label>
                    @if ($asaas->setting('service_name') || $asaas->setting('service_code'))
                        <div class="small" style="margin-bottom: 6px">Atual: <strong>{{ $asaas->setting('service_name') ?: '—' }}</strong>@if ($asaas->setting('service_code')) (código {{ $asaas->setting('service_code') }})@endif</div>
                    @endif
                    @if ($services)
                        <select name="service" id="f-service">
                            <option value="">— Manter o atual —</option>
                            @foreach ($services as $s)<option value="{{ $s['id'] }}|{{ $s['description'] }}|{{ $s['iss'] }}">{{ \Illuminate\Support\Str::limit($s['description'], 140) }}@if ($s['iss'] !== null) · ISS {{ rtrim(rtrim(number_format($s['iss'], 2, ',', ''), '0'), ',') }}%@endif</option>@endforeach
                        </select>
                    @endif
                    <div class="row" style="gap: 6px; margin-top: 6px">
                        <input type="text" name="busca" value="{{ session('nfse_search', 'advoca') }}" placeholder="Buscar serviço da prefeitura (ex.: advocacia, 17.14)" style="flex: 1">
                        <button class="btn" formaction="{{ route('billing.nfse.services') }}" formnovalidate><x-icon name="search" class="sm" /> Buscar na prefeitura</button>
                    </div>
                    <div class="hint">Advocacia é o item 17.14 da lista da LC 116/2003. Se a prefeitura não aparecer na busca, informe o código abaixo.</div>
                </div>
                <x-field name="service_code" label="Ou código do serviço" col="4">
                    <input type="text" name="service_code" id="f-service_code" maxlength="30" value="{{ old('service_code', $asaas->setting('service_code')) }}" placeholder="17.14">
                </x-field>
                <x-field name="service_name" label="Nome do serviço" col="8">
                    <input type="text" name="service_name" id="f-service_name" maxlength="250" value="{{ old('service_name', $asaas->setting('service_code') ? $asaas->setting('service_name') : '') }}" placeholder="Advocacia">
                </x-field>
                <x-field name="iss" label="Alíquota do ISS (%)" col="4" hint="Sociedade uniprofissional com ISS fixo: informe 0.">
                    <input type="text" inputmode="decimal" name="iss" id="f-iss" value="{{ old('iss', $asaas->setting('iss') !== null ? number_format((float) $asaas->setting('iss'), 2, ',', '') : '') }}">
                </x-field>
                <div class="field col-8"><label>&nbsp;</label><label class="check"><input type="checkbox" name="retain_iss" value="1" @checked(old('retain_iss', $asaas->setting('retain_iss')))> <span>ISS retido pelo tomador</span></label></div>
                <x-field name="description" label="Descrição do serviço na nota" col="12" hint="Use {descricao} (do lançamento), {cliente} e {processo}.">
                    <textarea name="description" id="f-description" rows="2" maxlength="1000">{{ old('description', $asaas->setting('description')) }}</textarea>
                </x-field>
                <x-field name="observations" label="Observações (opcional)" col="12">
                    <textarea name="observations" id="f-observations" rows="2" maxlength="1000">{{ old('observations', $asaas->setting('observations')) }}</textarea>
                </x-field>
                <div class="col-12 form-actions">
                    <button class="btn btn-ghost" formaction="{{ route('billing.nfse.test') }}" formnovalidate><x-icon name="plug" class="sm" /> Conferir dados fiscais no Asaas</button>
                    <span class="spacer"></span>
                    <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar NFS-e</button>
                </div>
            @endif
        </div>
    </form>
</div>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="book" /> Como funciona</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0"><strong>1.</strong> No <a class="link" href="{{ route('finance.index') }}">Financeiro</a>, lance os honorários com o cliente e clique em <em>Cobrar</em>. O cliente precisa ter CPF/CNPJ (e, no Banco Inter, endereço completo).</p>
            <p><strong>2.</strong> O sistema gera o boleto com Pix e mostra o link, a linha digitável e o Pix copia e cola, com botão para mandar pelo WhatsApp.</p>
            <p><strong>3.</strong> Pagou? O lançamento é baixado sozinho, o webhook <code>pagamento.recebido</code> avisa as automações e a NFS-e pode ser emitida automaticamente.</p>
            <p><strong>Bancos:</strong> o escritório precisa ter o convênio de cobrança contratado e liberar o acesso à API no portal de desenvolvedores do banco (o gerente PJ ajuda). Se a API de um banco não estiver disponível, a baixa pode ser feita importando o arquivo de retorno CNAB 240.</p>
            <p style="margin-bottom: 0"><strong>Testes:</strong> use o ambiente de testes (sandbox) de cada conta, que é gratuito. No sandbox do Asaas dá para simular o pagamento pelo painel; depois clique em <em>Atualizar situação</em>.</p>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><x-icon name="alert" /> Bom saber</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0">Multa de até 2% e juros de 1% ao mês são o usual para pessoa física (CDC, art. 52, § 1º).</p>
            <p style="margin-bottom: 0">Cancelar uma cobrança aqui também a cancela no banco. Estornar a baixa no Financeiro não devolve o dinheiro: faça o estorno no painel do Asaas ou no internet banking.</p>
        </div>
    </div>
</div>
</div>
@endsection
