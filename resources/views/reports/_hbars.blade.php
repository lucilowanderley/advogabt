{{-- Barras horizontais: $rows = [['label', 'value', 'percent'], ...], $money (bool), $color (css var) --}}
@php $money = $money ?? false; $max = max(1, collect($rows)->max('value') ?? 0); @endphp
@if (empty($rows))
    <x-empty icon="chart">Sem dados no período.</x-empty>
@else
    <ul class="hbars">
        @foreach ($rows as $r)
            <li>
                <div class="row between"><span class="hbar-label">{{ $r['label'] }}</span><span class="small nowrap"><span class="strong">{{ $money ? \App\Support\Format::money($r['value']) : number_format($r['value'], 0, ',', '.') }}</span> <span class="faint">{{ number_format($r['percent'], 1, ',', '.') }}%</span></span></div>
                <div class="progress"><span style="width: {{ round($r['value'] / $max * 100, 1) }}%; {{ isset($color) ? 'background: '.$color : '' }}"></span></div>
            </li>
        @endforeach
    </ul>
@endif
