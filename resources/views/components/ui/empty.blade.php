@props(['title', 'message' => null, 'description' => null, 'icon' => null])
<div class="empty-state"><span aria-hidden="true" class="empty-symbol">@if($icon)<x-ui.icon :name="$icon" />@else＋@endif</span><h3>{{ $title }}</h3><p>{{ $message ?? $description ?? $slot }}</p></div>
