<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\JoinApplication;
use App\Support\Audit;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstitutionController extends Controller
{
    public const PROFILE = ['name', 'region', 'city', 'address', 'website', 'contact_email', 'contact_phone', 'student_population'];

    public function index(Request $request): View
    {
        InstitutionAccess::requireSystem();
        $institutions = Institution::when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))->orderBy('name')->paginate(15)->withQueryString();

        return view('system.institutions', compact('institutions'));
    }

    public function create(Request $request): View
    {
        InstitutionAccess::requireSystem();
        $application = $request->filled('application') ? JoinApplication::where('uuid', $request->string('application'))->where('status', 'approved')->firstOrFail() : null;

        return view('system.institution-form', ['institution' => new Institution, 'application' => $application]);
    }

    private function rules(?Institution $institution = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['university', 'college', 'school', 'technical', 'other'])],
            'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('institutions')->ignore($institution?->id)],
            'country' => ['required', 'string', 'max:100'], 'region' => ['required', 'string', 'max:150'], 'city' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'], 'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'student_population' => ['required', 'integer', 'min:0', 'max:10000000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'locked_fields' => ['nullable', 'array'], 'locked_fields.*' => [Rule::in(self::PROFILE)],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        $data = $request->validate($this->rules() + ['application_uuid' => ['nullable', 'ulid']]);
        $institution = DB::transaction(function () use ($data): Institution {
            $application = ! empty($data['application_uuid']) ? JoinApplication::where('uuid', $data['application_uuid'])->lockForUpdate()->firstOrFail() : null;
            if ($application) {
                abort_unless($application->status === 'approved' && ! Institution::where('application_id', $application->id)->exists(), 422, 'Application already onboarded or not approved.');
            }
            $values = collect($data)->except(['logo', 'application_uuid'])->all();
            $institution = Institution::create($values + [
                'identifier' => Str::uuid()->toString(), 'status' => 'draft', 'created_by' => auth()->id(),
                'application_id' => $application?->id,
            ]);
            if ($application) {
                Audit::record('application.onboarding_started', $application, null, ['institution_id' => $institution->id]);
            }
            Audit::record('institution.created', $institution, $institution->id);

            return $institution;
        });
        $this->logo($request, $institution);

        return redirect()->route('institutions.edit', $institution)->with('status', 'Institution created as draft. Appoint an administrator and activate explicitly.');
    }

    public function edit(Institution $institution): View
    {
        InstitutionAccess::authorize($institution, 'institution.profile');

        return view('system.institution-form', compact('institution') + ['application' => null]);
    }

    public function update(Request $request, Institution $institution): RedirectResponse
    {
        InstitutionAccess::authorize($institution, 'institution.profile');
        $system = InstitutionAccess::system();
        $rules = $this->rules($institution);
        if (! $system) {
            abort_if($request->hasAny(['slug', 'type', 'country', 'status', 'locked_fields', 'identifier', 'application_id']), 403);
            $allowed = array_diff(self::PROFILE, $institution->locked_fields ?? []);
            $rules = array_intersect_key($rules, array_flip(array_merge($allowed, ['logo'])));
            foreach ($institution->locked_fields ?? [] as $field) {
                abort_if($request->has($field), 403);
            }
        }
        $data = $request->validate($rules);
        if ($system) {
            $data['locked_fields'] = $data['locked_fields'] ?? [];
        }
        DB::transaction(function () use ($data, $institution): void {
            $institution->update(collect($data)->except('logo')->all());
            Audit::record('institution.profile_updated', $institution, $institution->id, ['fields' => array_keys($data)]);
        });
        $this->logo($request, $institution);

        return back()->with('status', 'Institution profile saved.');
    }

    private function logo(Request $request, Institution $institution): void
    {
        if ($request->hasFile('logo')) {
            $institution->update(['logo_path' => $request->file('logo')->store('institution-logos', 'local')]);
        }
    }

    public function logoFile(Institution $institution): BinaryFileResponse
    {
        InstitutionAccess::authorize($institution);
        abort_unless($institution->logo_path && Storage::disk('local')->exists($institution->logo_path), 404);

        return response()->file(Storage::disk('local')->path($institution->logo_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function status(Request $request, Institution $institution): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'pending_activation', 'active', 'suspended', 'archived'])]]);
        DB::transaction(function () use ($institution, $data): void {
            Institution::whereKey($institution->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'active') {
                abort_unless($institution->memberships()->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'Institution Administrator'))->whereHas('user', fn ($query) => $query->where('status', 'active'))->exists(), 422, 'An accepted active Institution Administrator is required before activation.');
                if ($institution->application_id) {
                    $application = JoinApplication::whereKey($institution->application_id)->lockForUpdate()->firstOrFail();
                    abort_unless(in_array($application->status, ['approved', 'onboarded'], true), 422, 'The originating application must remain approved before activation.');
                    if ($application->status !== 'onboarded') {
                        $application->update(['status' => 'onboarded']);
                        Audit::record('application.onboarded', $application, null, ['institution_id' => $institution->id]);
                    }
                }
            }
            $institution->update($data + ($data['status'] === 'active' ? ['approved_by' => auth()->id(), 'approved_at' => now()] : []));
            Audit::record('institution.status_changed', $institution, $institution->id, $data);
        });

        return back()->with('status', 'Institution status updated.');
    }
}
