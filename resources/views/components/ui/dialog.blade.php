@props(['id', 'title', 'destructive' => false])
<dialog id="{{ $id }}" class="dialog"><h2>{{ $title }}</h2><div>{{ $slot }}</div><form method="dialog" class="button-row"><button class="button button-secondary" value="cancel">Cancel</button><button class="button {{ $destructive ? 'button-danger' : '' }}" value="confirm">Confirm preview</button></form></dialog>
