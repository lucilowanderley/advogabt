@props(['user', 'size' => ''])
<span {{ $attributes->merge(['class' => trim('avatar '.$size)]) }} style="background: {{ $user->color ?? '#c9a24d' }}" title="{{ $user->name }}">{{ \App\Support\Format::initials($user->name) }}</span>
