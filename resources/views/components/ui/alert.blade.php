@props(['error' => false])
<div {{ $attributes->class(['alert', 'alert-error' => $error]) }} role="{{ $error ? 'alert' : 'status' }}">{{ $slot }}</div>
