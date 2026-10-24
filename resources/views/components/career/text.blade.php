@props(['text'=>''])
@foreach(preg_split('/(https?:\/\/[^\s<>]+)/u', $text ?? '', -1, PREG_SPLIT_DELIM_CAPTURE) as $part)@if(preg_match('/^https?:\/\//i',$part) && filter_var($part,FILTER_VALIDATE_URL))<a href="{{ $part }}" rel="noopener noreferrer">{{ $part }}</a>@else{{ $part }}@endif @endforeach
