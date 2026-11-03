<div class="tabs">
    <a href="{{ route('billing.index') }}" class="{{ request()->routeIs('billing.index', 'billing.show', 'billing.create') ? 'active' : '' }}"><x-icon name="dollar" class="sm" /> Cobranças</a>
    <a href="{{ route('billing.invoices') }}" class="{{ request()->routeIs('billing.invoices') ? 'active' : '' }}"><x-icon name="file-text" class="sm" /> Notas fiscais</a>
    <a href="{{ route('finance.index') }}"><x-icon name="wallet" class="sm" /> Financeiro</a>
    @can('admin')<a href="{{ route('billing.settings') }}" class="{{ request()->routeIs('billing.settings') ? 'active' : '' }}"><x-icon name="settings" class="sm" /> Configurar</a>@endcan
</div>
