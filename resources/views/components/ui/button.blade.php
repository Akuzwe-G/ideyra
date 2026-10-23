@props(['href' => null, 'variant' => 'primary'])
@if($href)<a href="{{ $href }}" {{ $attributes->class(['button', 'button-secondary' => $variant === 'secondary']) }}>{{ $slot }}</a>
@else<button {{ $attributes->merge(['type' => 'button'])->class(['button', 'button-secondary' => $variant === 'secondary']) }}>{{ $slot }}</button>@endif
