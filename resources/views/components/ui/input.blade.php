@props(['name', 'label', 'error' => null, 'hint' => null, 'type' => 'text', 'id' => null])
@php $fieldId = $id ?? $name; $error ??= $errors->first($name); @endphp
<div class="field"><label for="{{ $fieldId }}">{{ $label }}@if($attributes->get('required'))<span class="required-mark" aria-hidden="true">*</span>@endif</label><input id="{{ $fieldId }}" name="{{ $name }}" aria-label="{{ $label }}" type="{{ $type }}" @if($error) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-help" {{ $attributes }}>
<p id="{{ $fieldId }}-help" class="{{ $error ? 'field-error' : 'field-hint' }}">{{ $error ?? $hint }}</p></div>
