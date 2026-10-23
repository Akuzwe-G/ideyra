<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>New Ideyra enquiry</title></head>
<body style="margin:0;background:{{ config('ideyra.brand.sky') }};font:16px/1.7 Arial,sans-serif;color:{{ config('ideyra.brand.navy') }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:auto;background:{{ config('ideyra.brand.white') }};border-radius:12px;"><tr><td style="padding:32px;">
<p style="font-size:22px;font-weight:bold;">{{ config('ideyra.name') }}</p>
<p style="color:{{ config('ideyra.brand.slate') }};">{{ config('ideyra.tagline') }}</p>
<h1 style="font-size:24px;">A new conversation has started.</h1>
<p>Enquiry #{{ $enquiry->id }} · {{ ucfirst($enquiry->category) }}</p>
<table cellpadding="6" cellspacing="0"><tr><th scope="row" align="left">From</th><td>{{ $enquiry->name }}</td></tr><tr><th scope="row" align="left">Email</th><td>{{ $enquiry->email }}</td></tr><tr><th scope="row" align="left">Subject</th><td>{{ $enquiry->subject }}</td></tr></table>
<h2 style="font-size:18px;">Message</h2>
<div style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $enquiry->message }}</div>
<p style="font-size:13px;color:{{ config('ideyra.brand.slate') }};">Consent recorded: {{ $enquiry->consented_at->toIso8601String() }}. This visitor-supplied content should be treated as untrusted. Reply to this email to contact the visitor.</p>
<p style="font-size:12px;">© {{ config('ideyra.copyright_year') }} {{ config('ideyra.name') }} · v{{ config('ideyra.version') }}</p>
</td></tr></table></td></tr></table></body></html>
