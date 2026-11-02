@extends('layouts.app', ['title' => 'Documentação da API · Integrações'])
@php
    $base = url('/api/v1');
    $endpoints = [
        'Geral' => [
            ['GET', '/status', 'leitura', 'Testa o token e mostra o escritório e as permissões.'],
            ['GET', '/eventos', 'leitura', 'Lista os eventos de webhook disponíveis.'],
        ],
        'Processos' => [
            ['GET', '/processos', 'leitura', 'Lista processos. Filtros: search, status, area, number, contact_id, updated_since.'],
            ['GET', '/processos/{id}', 'leitura', 'Detalhes do processo com todos os andamentos.'],
            ['POST', '/processos', 'escrita', 'Cadastra um processo (title obrigatório).'],
            ['PATCH', '/processos/{id}', 'escrita', 'Altera campos do processo (fase, situação, responsável…).'],
            ['POST', '/processos/{id}/andamentos', 'escrita', 'Registra um andamento: description e date (opcional).'],
        ],
        'Contatos e CRM' => [
            ['GET', '/contatos', 'leitura', 'Lista contatos. Filtros: search, phone, document, email, category, crm_stage.'],
            ['GET', '/contatos/{id}', 'leitura', 'Detalhes do contato com a lista de processos dele.'],
            ['POST', '/contatos', 'escrita', 'Cadastra um contato ou lead (name obrigatório).'],
            ['PATCH', '/contatos/{id}', 'escrita', 'Altera o contato, por exemplo a etapa do funil (crm_stage).'],
        ],
        'Prazos e tarefas' => [
            ['GET', '/prazos', 'leitura', 'Prazos processuais em aberto que vencem em até N dias (days, padrão '.$deadlineDays.'), inclusive atrasados.'],
            ['GET', '/tarefas', 'leitura', 'Lista tarefas. Filtros: status (abertas, pendente, fazendo, concluida), deadlines_only, user_id, legal_case_id, due_from, due_to.'],
            ['GET', '/tarefas/{id}', 'leitura', 'Detalhes da tarefa.'],
            ['POST', '/tarefas', 'escrita', 'Cria tarefa ou prazo: title, due_at, is_deadline, users[], legal_case_id…'],
            ['PATCH', '/tarefas/{id}', 'escrita', 'Altera a tarefa, por exemplo status = concluida.'],
        ],
        'Agenda' => [
            ['GET', '/agenda', 'leitura', 'Compromissos entre from e to (padrão: próximos 30 dias). Filtros: type, user_id, contact_id.'],
            ['POST', '/agenda', 'escrita', 'Agenda um compromisso: title, starts_at, type, location…'],
        ],
        'Financeiro' => [
            ['GET', '/financeiro', 'leitura', 'Lançamentos. Filtros: type (receita, despesa), situation (aberto, atrasado, pago), due_from, due_to, contact_id.'],
            ['GET', '/financeiro/{id}', 'leitura', 'Detalhes do lançamento.'],
            ['POST', '/financeiro', 'escrita', 'Lança receita ou despesa: type, description, amount, due_date.'],
            ['POST', '/financeiro/{id}/baixa', 'escrita', 'Dá baixa (pagamento): paid_at e payment_method opcionais.'],
        ],
        'Webhooks via API' => [
            ['GET', '/webhooks', 'leitura', 'Webhooks criados por este token.'],
            ['POST', '/webhooks', 'escrita', 'Assina eventos: url e events[] (ou ["*"]). Devolve o secret.'],
            ['DELETE', '/webhooks/{id}', 'escrita', 'Cancela a assinatura.'],
        ],
    ];
    $sample = [
        'id' => '9b1f0c1e-6a3e-4c55-9d7b-1f7a9d2a7c10',
        'event' => 'prazo.vencendo',
        'event_label' => 'Prazo vencendo',
        'created_at' => now()->startOfHour()->toIso8601String(),
        'source' => ['system' => 'ADVOGABT', 'office' => config('app.name'), 'url' => config('app.url')],
        'data' => [
            'id' => 42, 'title' => 'Contestação', 'status' => 'pendente', 'is_deadline' => true,
            'due_at' => today()->addDays(2)->setTime(18, 0)->toIso8601String(), 'days_left' => 2,
            'case' => ['id' => 7, 'number' => '0001234-56.2026.8.26.0100', 'title' => 'Ação de cobrança'],
            'contact' => ['id' => 3, 'name' => 'Maria Souza', 'whatsapp' => '(11) 98888-7777'],
            'assignees' => [['id' => 1, 'name' => 'Dra. Ana', 'email' => 'ana@escritorio.com.br']],
        ],
    ];
@endphp

@section('content')
<div class="breadcrumb"><a href="{{ route('integrations.index') }}">Integrações</a> / Documentação da API</div>
<div class="page-head">
    <div>
        <h1>Documentação da API</h1>
        <div class="sub">API REST v1 e webhooks do ADVOGABT, para n8n, Make, Zapier, bots e sistemas próprios.</div>
    </div>
</div>

@include('integrations._tabs')

<div class="docs">
    <div class="card mb">
        <div class="card-head"><h3><x-icon name="key" /> Autenticação</h3></div>
        <div class="card-body">
            <p>Endereço base: <code>{{ $base }}</code>. Envie o token criado em <strong>Integrações › API REST</strong> no cabeçalho <code>Authorization: Bearer advbt_...</code> (ou <code>X-Api-Key</code>). As respostas são JSON, com a lista em <code>data</code> e a paginação em <code>meta</code> e <code>links</code> (<code>page</code> e <code>per_page</code> até 100). Datas em ISO 8601 (AAAA-MM-DD).</p>
            <pre class="code-block">curl {{ $base }}/prazos?days=7 \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Accept: application/json"</pre>
            <pre class="code-block">curl -X POST {{ $base }}/processos/7/andamentos \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"description": "Juntada de petição", "date": "{{ today()->toDateString() }}"}'</pre>
            <p class="small muted" style="margin: 0">Erros: 401 token inválido · 403 token sem permissão de escrita · 404 registro não encontrado · 422 dados inválidos (detalhes em <code>errors</code>) · 429 limite de {{ config('services.integrations.api_rate_limit') }} chamadas por minuto.</p>
        </div>
    </div>

    <div class="card mb">
        <div class="card-head"><h3><x-icon name="list" /> Endpoints</h3></div>
        <div class="table-wrap">
            <table class="table endpoints">
                <tbody>
                @foreach ($endpoints as $group => $rows)
                    <tr class="group-row"><th colspan="4">{{ $group }}</th></tr>
                    @foreach ($rows as [$method, $path, $access, $desc])
                        <tr>
                            <td class="nowrap"><span class="method m-{{ strtolower($method) }}">{{ $method }}</span></td>
                            <td class="nowrap"><code>/api/v1{{ $path }}</code></td>
                            <td class="small muted">{{ $desc }}</td>
                            <td><span class="badge {{ $access === 'escrita' ? 'orange' : 'blue' }}">{{ $access }}</span></td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb" id="webhooks">
        <div class="card-head"><h3><x-icon name="webhook" /> Webhooks</h3></div>
        <div class="card-body">
            <p>Cada evento é enviado por <strong>POST</strong> em JSON para a URL cadastrada, com os cabeçalhos <code>X-Advogabt-Event</code>, <code>X-Advogabt-Delivery</code> (id único, use para ignorar duplicados) e <code>X-Advogabt-Signature</code> = <code>sha256=</code> + HMAC SHA-256 do corpo usando o segredo do webhook. Responda com qualquer código 2xx em até {{ config('services.integrations.webhook_timeout') }} segundos; em caso de falha o envio é repetido até {{ \App\Models\WebhookDelivery::MAX_ATTEMPTS }} vezes.</p>
            <div class="table-wrap mb">
                <table class="table">
                    <thead><tr><th>Evento</th><th>Quando</th></tr></thead>
                    <tbody>
                    @foreach ($events as $key => $e)
                        <tr><td class="nowrap"><code>{{ $key }}</code></td><td class="small">{{ $e['description'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small muted">Exemplo de corpo (<code>prazo.vencendo</code>). O campo <code>data</code> traz o registro no mesmo formato da API.</p>
            <pre class="code-block">{{ json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            <p class="small muted">Conferir a assinatura no n8n (opcional): ligue "Raw Body" no nó Webhook e use um nó <em>Code</em> (o n8n precisa de <code>NODE_FUNCTION_ALLOW_BUILTIN=crypto</code>):</p>
            <pre class="code-block">const crypto = require('crypto');
const body = (await this.helpers.getBinaryDataBuffer(0, 'data')).toString('utf8');
const expected = 'sha256=' + crypto.createHmac('sha256', 'SEU_SEGREDO').update(body).digest('hex');
if ($input.first().json.headers['x-advogabt-signature'] !== expected) throw new Error('Assinatura inválida');
return [{ json: JSON.parse(body) }];</pre>
            <p class="small muted">Em PHP:</p>
            <pre class="code-block">$body = file_get_contents('php://input');
$ok = hash_equals('sha256='.hash_hmac('sha256', $body, 'SEU_SEGREDO'), $_SERVER['HTTP_X_ADVOGABT_SIGNATURE'] ?? '');</pre>
        </div>
    </div>

    <div class="card mb">
        <div class="card-head"><h3><x-icon name="clock" /> Prazos vencendo e agendador</h3></div>
        <div class="card-body small">
            <p>O evento <code>prazo.vencendo</code> é enviado uma vez para cada prazo processual em aberto que vence em até <strong>{{ $deadlineDays }} dia(s)</strong> (ajuste com <code>INTEGRATIONS_DEADLINE_DAYS</code> no <code>.env</code>). Se o vencimento for alterado, o aviso é enviado de novo.</p>
            <p>A verificação roda a cada 15 minutos enquanto alguém usa o sistema. Para que rode mesmo com o sistema fechado, no Windows crie uma tarefa no <strong>Agendador de Tarefas</strong> que execute a cada minuto:</p>
            <pre class="code-block">C:\laragon\bin\php\php-8.3\php.exe C:\laragon\www\advogabt\artisan schedule:run</pre>
            <p style="margin: 0">Também é possível rodar manualmente com <code>php artisan integracoes:rotina</code>, pelo botão "Verificar prazos agora" na aba Webhooks, ou fazer o n8n consultar <code>GET /api/v1/prazos</code> com um nó <em>Schedule Trigger</em>.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3><x-icon name="sparkles" /> Receitas prontas para o n8n</h3></div>
        <div class="card-body small">
            <ol class="recipes">
                <li><strong>Avisar o cliente de um novo andamento.</strong> Webhook (evento <code>andamento.criado</code>) → filtrar quando <code>data.case.client.whatsapp</code> existe → enviar mensagem pelo WhatsApp (Evolution API ou API oficial).</li>
                <li><strong>Lembrete de prazo para a equipe.</strong> Webhook (<code>prazo.vencendo</code>) → e-mail ou WhatsApp para cada pessoa em <code>data.assignees</code>.</li>
                <li><strong>Lead do site direto no CRM.</strong> Formulário do site → <code>POST /api/v1/contatos</code> com <code>crm_stage: "lead"</code> e <code>origin: "site"</code>.</li>
                <li><strong>Baixa automática de Pix.</strong> Aviso do banco (Asaas, Inter) → <code>POST /api/v1/financeiro/{id}/baixa</code> → o ADVOGABT dispara <code>pagamento.recebido</code>.</li>
                <li><strong>Relatório semanal.</strong> Schedule Trigger → <code>GET /api/v1/prazos?days=7</code> e <code>GET /api/v1/financeiro?situation=atrasado</code> → e-mail para o sócio.</li>
            </ol>
        </div>
    </div>
</div>
@endsection
