@props(['status'])
<span class="badge badge-ticket-{{ $status->value }}">{{ $status->label() }}</span>
