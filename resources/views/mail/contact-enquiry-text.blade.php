{{ config('ideyra.name') }} — New contact enquiry #{{ $enquiry->id }}
{{ config('ideyra.tagline') }}

Category: {{ ucfirst($enquiry->category) }}
From: {!! $enquiry->name !!}
Email: {!! $enquiry->email !!}
Subject: {!! $enquiry->subject !!}

Message:
{!! $enquiry->message !!}

Consent recorded: {{ $enquiry->consented_at->toIso8601String() }}
Visitor-supplied content is untrusted. Reply to contact the visitor.

© {{ config('ideyra.copyright_year') }} {{ config('ideyra.name') }} · v{{ config('ideyra.version') }}
