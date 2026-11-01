@php $max = max(1, collect($monthly)->max(fn ($m) => max($m['receita'], $m['despesa']))); @endphp
<div class="bars {{ count($monthly) > 12 ? 'bars-dense' : '' }}">
    @foreach ($monthly as $m)
        <div class="bar-group">
            <div class="pair">
                <div class="bar in" style="height: {{ round($m['receita'] / $max * 100) }}%" title="{{ $m['label'] }} · Receitas: {{ \App\Support\Format::money($m['receita']) }}"></div>
                <div class="bar out" style="height: {{ round($m['despesa'] / $max * 100) }}%" title="{{ $m['label'] }} · Despesas: {{ \App\Support\Format::money($m['despesa']) }}"></div>
            </div>
            <div class="lbl">{{ $m['label'] }}</div>
        </div>
    @endforeach
</div>
