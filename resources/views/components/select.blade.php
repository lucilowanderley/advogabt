@props(['name', 'options' => [], 'value' => null, 'placeholder' => null])
<select name="{{ $name }}" id="f-{{ $name }}" {{ $attributes }}>
    @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
    @foreach ($options as $key => $label)
        <option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $label }}</option>
    @endforeach
</select>
