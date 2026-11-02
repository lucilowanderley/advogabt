@props(['pricing', 'note' => null])
@php $info = \App\Support\Ai\Drivers::PRICING[$pricing] ?? ['label' => $pricing, 'color' => '']; @endphp
<span {{ $attributes->merge(['class' => 'badge '.$info['color']]) }} @if ($note) title="{{ $note }}" @endif>{{ $info['label'] }}</span>
