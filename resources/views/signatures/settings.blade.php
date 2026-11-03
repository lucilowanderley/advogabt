@extends('layouts.app', ['title' => 'Assinatura digital · Integrações'])
@php use App\Models\SignatureProvider; @endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Assinatura digital</div>
<div class="page-head">
    <div>
        <h1>Assinatura digital</h1>
        <div class="sub">Contas do escritório na ClickSign e na D4Sign para enviar contratos e procurações gerados pelos Modelos.</div>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('signatures.index') }}"><x-icon name="pen" class="sm" /> Documentos enviados</a>
    </div>
</div>

@include('integrations._tabs')

<div class="grid grid-3 mb">
    @php $ready = collect($providers)->filter(fn ($p) => $p->exists && $p->active && $p->isConfigured()); @endphp
    <div class="card kpi">
        <div class="kpi-icon {{ $ready->isNotEmpty() ? 'green' : 'red' }}"><x-icon name="pen" /></div>
        <div><div class="value" style="font-size: 18px">{{ $ready->isNotEmpty() ? $ready->map->label->implode(' e ') : 'Nenhuma plataforma' }}</div><div class="label">{{ $ready->isNotEmpty() ? 'Pronta(s) para envio' : 'Configure abaixo (ou use o certificado ICP-Brasil)' }}</div></div>
    </div>
    <div class="card kpi"><div class="kpi-icon"><x-icon name="clock" /></div><div><div class="value">{{ $pending }}</div><div class="label">Documento(s) aguardando assinatura</div></div></div>
    <div class="card kpi"><div class="kpi-icon green"><x-icon name="check-check" /></div><div><div class="value">{{ $signedMonth }}</div><div class="label">Assinado(s) neste mês</div></div></div>
</div>

@php $local = \App\Support\Signature\Signatures::isLocalUrl(url('/')); @endphp
@if ($local)
    <div class="alert warning small"><x-icon name="alert" /> <div>O sistema está em um endereço local (<code>{{ request()->getHost() }}</code>), então a ClickSign e a D4Sign não conseguem avisar quando alguém assina. Não tem problema: o sistema <strong>consulta a situação sozinho</strong> pela rotina das integrações (a cada 30 minutos no primeiro dia, depois a cada poucas horas), e na tela do documento há o botão <strong>Atualizar situação</strong>. Quando o sistema for publicado com domínio próprio, cadastre o endereço de aviso mostrado em cada plataforma.</div></div>
@endif

<div class="grid grid-main">
<div class="stack">
    @foreach ($providers as $driver => $p)
        @php $info = SignatureProvider::DRIVERS[$driver]; @endphp
        <form method="post" action="{{ route('signatures.providers.save', $driver) }}" class="card" autocomplete="off">
            @csrf
            <div class="card-head">
                <h3><x-icon name="pen" /> {{ $info['label'] }} <x-ai-pricing :pricing="$info['pricing']" /></h3>
                @if ($p->exists)
                    <span class="badge {{ $p->last_test_ok ? 'green' : ($p->last_test_ok === false ? 'red' : '') }}">{{ $p->last_test_ok ? 'conectada' : ($p->last_test_ok === false ? 'falhou no teste' : ($p->active ? 'não testada' : 'desativada')) }}</span>
                @endif
            </div>
            <div class="card-body form-grid">
                <div class="col-12 small muted">{{ $info['note'] }} <a class="link" target="_blank" rel="noopener" href="{{ $info['site'] }}">Site <x-icon name="external" class="sm" /></a></div>
                <x-field name="environment" label="Ambiente" col="12">
                    <select name="environment" id="f-environment-{{ $driver }}">@foreach (SignatureProvider::ENVIRONMENTS as $k => $l)<option value="{{ $k }}" @selected(old('environment', $p->environment) === $k)>{{ $l }}</option>@endforeach</select>
                </x-field>
                <x-field name="api_token" :label="$driver === 'd4sign' ? 'Token API' : 'Chave de acesso (Access Token)'" :col="$driver === 'd4sign' ? 6 : 12" :hint="$p->api_token ? 'Já cadastrada. Deixe em branco para manter.' : $info['token_help']">
                    <input type="password" name="api_token" id="f-api_token-{{ $driver }}" autocomplete="new-password" maxlength="500" placeholder="{{ $p->api_token ? '••••••••' : '' }}">
                </x-field>
                @if ($driver === 'd4sign')
                    <x-field name="crypt_key" label="Crypt Key" col="6" :hint="$p->crypt_key ? 'Já cadastrada. Deixe em branco para manter.' : null">
                        <input type="password" name="crypt_key" id="f-crypt_key" autocomplete="new-password" maxlength="500" placeholder="{{ $p->crypt_key ? '••••••••' : '' }}">
                    </x-field>
                    <x-field name="safe_uuid" label="Cofre onde os documentos ficam" col="8">
                        <select name="safe_uuid" id="f-safe_uuid">
                            <option value="">— Clique em "Carregar cofres" —</option>
                            @if ($p->safe_uuid)<option value="{{ $p->safe_uuid }}" selected>{{ $p->safe_name ?: $p->safe_uuid }}</option>@endif
                        </select>
                        <input type="hidden" name="safe_name" value="{{ $p->safe_name }}">
                    </x-field>
                    <div class="field col-4"><label>&nbsp;</label><button type="button" class="btn" data-load-safes="{{ route('signatures.safes') }}"><x-icon name="refresh" class="sm" /> Carregar cofres</button></div>
                    <div class="col-12 small faint" data-safes-status></div>
                @endif
                <div class="field col-12">
                    <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $p->exists ? $p->active : true))> <span>Disponível para envios</span></label>
                    <label class="check" style="margin-left: 18px"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $p->is_default))> <span>Plataforma padrão</span></label>
                </div>
                @if ($p->exists)
                    <div class="field col-12">
                        <label>Endereço de aviso (webhook)</label>
                        <div class="copy-field"><code data-copy-source>{{ $p->webhook_url }}</code></div>
                        <div class="hint">
                            @if ($driver === 'clicksign')
                                Na ClickSign: Configurações › Webhooks › Adicionar, cole este endereço e marque os eventos de assinatura, recusa, cancelamento e finalização.
                            @else
                                A D4Sign recebe este endereço automaticamente a cada documento enviado (quando o sistema está publicado).
                            @endif
                            Só funciona com o sistema publicado na internet; no Laragon a rotina consulta a situação.
                            @if ($p->last_webhook_at) Último aviso recebido {{ $p->last_webhook_at->diffForHumans() }}.@endif
                        </div>
                    </div>
                @endif
                @if ($p->last_test_ok === false && $p->last_error)<div class="col-12 small text-red">{{ $p->last_error }}</div>@endif
                <div class="col-12 form-actions">
                    @if ($p->exists)
                        <button class="btn btn-ghost" formaction="{{ route('signatures.providers.test', $driver) }}" formnovalidate><x-icon name="plug" class="sm" /> Testar conexão</button>
                    @endif
                    <span class="spacer"></span>
                    <button class="btn btn-primary"><x-icon name="check" class="sm" /> Salvar</button>
                </div>
            </div>
        </form>
        @if ($p->exists)
            <form method="post" action="{{ route('signatures.providers.destroy', $driver) }}" data-confirm="Desconectar a {{ $info['label'] }}? As chaves serão apagadas." style="margin-top: -8px; text-align: right">
                @csrf @method('delete')<button class="btn btn-xs btn-ghost"><x-icon name="trash" class="sm" /> Desconectar {{ $info['label'] }}</button>
            </form>
        @endif
    @endforeach
</div>

<div class="stack">
    <div class="card">
        <div class="card-head"><h3><x-icon name="key" /> Certificado ICP-Brasil</h3> <x-ai-pricing pricing="gratis" /></div>
        <div class="card-body small muted">
            <p style="margin-top: 0">{{ SignatureProvider::MANUAL['note'] }}</p>
            <p style="margin-bottom: 0">Na ClickSign e na D4Sign também dá para <strong>exigir certificado ICP-Brasil</strong> dos signatários: é uma opção na hora do envio.</p>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><x-icon name="book" /> Como funciona</h3></div>
        <div class="card-body small muted">
            <p style="margin-top: 0"><strong>1.</strong> Em <a class="link" href="{{ route('templates.index') }}">Modelos</a>, escolha o contrato ou a procuração, o cliente e clique em <em>Enviar para assinatura</em>. Também dá para enviar pela ficha do processo ou do cliente, ou mandar um PDF pronto.</p>
            <p><strong>2.</strong> O sistema gera o PDF, envia à plataforma e cada signatário recebe o link por e-mail (ou o código por SMS/WhatsApp).</p>
            <p><strong>3.</strong> Quando todos assinam, o PDF assinado fica na ficha do cliente/processo e, se os Arquivos na nuvem estiverem ligados, vai para a pasta "{{ \App\Support\Signature\Signatures::CLOUD_FOLDER }}". O webhook <code>assinatura.concluida</code> avisa automações (n8n, Make, Zapier).</p>
            <p style="margin-bottom: 0"><strong>Testes:</strong> comece no ambiente de testes (sandbox) de cada plataforma, que é gratuito, e troque para produção quando contratar o plano.</p>
        </div>
    </div>
</div>
</div>
@endsection
