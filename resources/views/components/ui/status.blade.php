@props(['status'])
<span class="status-badge status-{{ str_replace('_', '-', $status) }}"><span aria-hidden="true"></span>{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
