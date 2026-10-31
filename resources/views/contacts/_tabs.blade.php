<div class="page-head">
    <div>
        <h1>Contatos e CRM</h1>
        <div class="sub">Clientes, partes, testemunhas e o funil de novos clientes.</div>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="{{ route('contacts.create') }}"><x-icon name="plus" class="sm" /> Novo contato</a>
    </div>
</div>
<div class="tabs">
    <a href="{{ route('contacts.index', ['view' => 'lista']) }}" class="{{ $view === 'lista' ? 'active' : '' }}"><x-icon name="list" class="sm" /> Lista</a>
    <a href="{{ route('contacts.index', ['view' => 'funil']) }}" class="{{ $view === 'funil' ? 'active' : '' }}"><x-icon name="funnel" class="sm" /> Funil de clientes (CRM)</a>
</div>
