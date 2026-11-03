<div class="tabs">
    <a href="{{ route('integrations.index') }}" class="{{ request()->routeIs('integrations.index') ? 'active' : '' }}"><x-icon name="plug" class="sm" /> Visão geral</a>
    @can('admin')
        <a href="{{ route('integrations.tokens.index') }}" class="{{ request()->routeIs('integrations.tokens.*') ? 'active' : '' }}"><x-icon name="key" class="sm" /> API REST</a>
        <a href="{{ route('integrations.webhooks.index') }}" class="{{ request()->routeIs('integrations.webhooks.*') ? 'active' : '' }}"><x-icon name="webhook" class="sm" /> Webhooks</a>
        <a href="{{ route('cloud.settings') }}" class="{{ request()->routeIs('cloud.*') ? 'active' : '' }}"><x-icon name="cloud" class="sm" /> Arquivos na nuvem</a>
    @endcan
    @can('admin')
        <a href="{{ route('signatures.settings') }}" class="{{ request()->routeIs('signatures.settings') ? 'active' : '' }}"><x-icon name="pen" class="sm" /> Assinatura digital</a>
        <a href="{{ route('billing.settings') }}" class="{{ request()->routeIs('billing.settings') ? 'active' : '' }}"><x-icon name="dollar" class="sm" /> Cobrança e NFS-e</a>
    @endcan
    <a href="{{ route('agenda-email.index') }}" class="{{ request()->routeIs('agenda-email.*') ? 'active' : '' }}"><x-icon name="calendar" class="sm" /> Agenda e e-mail</a>
    <a href="{{ route('integrations.docs') }}" class="{{ request()->routeIs('integrations.docs') ? 'active' : '' }}"><x-icon name="book" class="sm" /> Documentação da API</a>
</div>
