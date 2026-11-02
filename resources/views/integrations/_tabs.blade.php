<div class="tabs">
    <a href="{{ route('integrations.index') }}" class="{{ request()->routeIs('integrations.index') ? 'active' : '' }}"><x-icon name="plug" class="sm" /> Visão geral</a>
    @can('admin')
        <a href="{{ route('integrations.tokens.index') }}" class="{{ request()->routeIs('integrations.tokens.*') ? 'active' : '' }}"><x-icon name="key" class="sm" /> API REST</a>
        <a href="{{ route('integrations.webhooks.index') }}" class="{{ request()->routeIs('integrations.webhooks.*') ? 'active' : '' }}"><x-icon name="webhook" class="sm" /> Webhooks</a>
    @endcan
    <a href="{{ route('integrations.docs') }}" class="{{ request()->routeIs('integrations.docs') ? 'active' : '' }}"><x-icon name="book" class="sm" /> Documentação da API</a>
</div>
