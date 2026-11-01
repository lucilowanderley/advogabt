<div class="card kpi">
    <div class="kpi-icon {{ $color ?? '' }}"><x-icon :name="$icon" /></div>
    <div><div class="value {{ $valueClass ?? '' }}">{{ $value }}</div><div class="label">{{ $label }}</div></div>
</div>
