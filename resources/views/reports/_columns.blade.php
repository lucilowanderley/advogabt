{{-- Colunas mensais de uma série: $series = ['Y-m' => valor], $months = ['Y-m' => rótulo] --}}
@php $money = $money ?? false; $max = max(1, max($series ?: [0])); @endphp
<div class="bars bars-single">
    @foreach ($series as $key => $value)
        <div class="bar-group">
            <div class="val">{{ $value ? ($money ? number_format($value / 1000, 1, ',', '.').'k' : (int) $value) : '' }}</div>
            <div class="pair"><div class="bar in" style="height: {{ round($value / $max * 100) }}%; {{ isset($color) ? 'background: '.$color : '' }}" title="{{ $months[$key] }}: {{ $money ? \App\Support\Format::money($value) : (int) $value }}"></div></div>
            <div class="lbl">{{ $months[$key] }}</div>
        </div>
    @endforeach
</div>
