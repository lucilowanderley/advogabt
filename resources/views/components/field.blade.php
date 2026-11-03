@props(['name', 'label' => null, 'col' => 12, 'hint' => null])
<div {{ $attributes->merge(['class' => 'field col-'.$col]) }}>
    @if ($label)<label for="f-{{ $name }}">{{ $label }}</label>@endif
    {{ $slot }}
    @if ($hint)<div class="hint">{{ $hint }}</div>@endif
    @error($name)<div class="error">{{ $message }}</div>@enderror
</div>
