{{ config('ideyra.name') }} — application received
Reference: {{ $application->reference }}
Type: {{ $application->type }}
Applicant: {!! $application->payload['applicant_name'] !!}
Institution: {!! $application->payload['institution_name'] !!}
Location: {!! $application->country !!}, {!! $application->payload['city'] !!}
Submitted: {{ $application->created_at->toIso8601String() }}
No institution or account has been created. Review is required.
Capabilities of interest: {!! implode(', ', $application->payload['features'] ?? []) !!}
Challenges: {!! \Illuminate\Support\Str::limit($application->payload['project_challenges'] ?? '', 500) !!}
Secure review (System Administrator login required): {!! rtrim(config('app.url'), '/').route('system.applications.show', $application, false) !!}
