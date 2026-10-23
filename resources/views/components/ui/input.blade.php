@props(['name', 'label', 'error' => null, 'hint' => null, 'type' => 'text'])
<div class="field"><label for="{{ $name }}">{{ $label }}</label><input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($error) aria-invalid="true" @endif aria-describedby="{{ $name }}-help" {{ $attributes }}>
<p id="{{ $name }}-help" class="{{ $error ? 'field-error' : 'field-hint' }}">{{ $error ?? $hint }}</p></div>
