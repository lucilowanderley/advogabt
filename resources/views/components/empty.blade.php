@props(['icon' => 'file-text'])
<div class="empty"><x-icon :name="$icon" /><div>{{ $slot }}</div></div>
