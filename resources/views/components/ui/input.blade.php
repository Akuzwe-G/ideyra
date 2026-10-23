@props(['name', 'label', 'error' => null, 'hint' => null, 'type' => 'text', 'id' => null])
@php $fieldId = $id ?? $name; @endphp
<div class="field"><label for="{{ $fieldId }}">{{ $label }}</label><input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" @if($error) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-help" {{ $attributes }}>
<p id="{{ $fieldId }}-help" class="{{ $error ? 'field-error' : 'field-hint' }}">{{ $error ?? $hint }}</p></div>
