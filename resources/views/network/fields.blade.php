@foreach($fields as $name=>$spec)
@php($value=old($name,$values[$name]??''))
<label>{{ $spec[0] }}
@if(($spec[1]??'text')==='textarea')<textarea name="{{ $name }}" rows="4" @if($spec[2]??false) required @endif maxlength="{{ $spec[3]??12000 }}">{{ $value }}</textarea>
@else<input type="{{ $spec[1]??'text' }}" name="{{ $name }}" value="{{ $value }}" @if($spec[2]??false) required @endif @if(isset($spec[3])) maxlength="{{ $spec[3] }}" @endif @if(($spec[1]??'')==='number') min="0" @endif>
@endif</label>
@endforeach
