<?php

namespace App\Http\Controllers;

use App\Models\AcademicClass;
use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\Intake;
use App\Models\Membership;
use App\Models\Programme;
use App\Support\AcademicWorkflow;
use App\Support\Audit;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AcademicController extends Controller
{
    public function index(Institution $institution, string $type): View
    {
        InstitutionAccess::authorize($institution, AcademicWorkflow::permission($type));
        $model = AcademicWorkflow::TYPES[$type];

        return view('institution.academic', [
            'institution' => $institution, 'type' => $type, 'records' => $model::where('institution_id', $institution->id)->orderBy('name')->paginate(15),
            'editing' => null,
        ] + $this->options($institution));
    }

    private function options(Institution $institution): array
    {
        return ['years' => $institution->id ? AcademicYear::where('institution_id', $institution->id)->get() : collect(),
            'programmes' => Programme::where('institution_id', $institution->id)->get(), 'intakes' => Intake::where('institution_id', $institution->id)->get()];
    }

    public function edit(Institution $institution, string $type, int $record): View
    {
        InstitutionAccess::authorize($institution, AcademicWorkflow::permission($type));
        $model = AcademicWorkflow::TYPES[$type];
        $editing = $model::where('institution_id', $institution->id)->findOrFail($record);

        return view('institution.academic', compact('institution', 'type', 'editing') + ['records' => $model::where('institution_id', $institution->id)->orderBy('name')->paginate(15)] + $this->options($institution));
    }

    public function save(Request $request, Institution $institution, string $type, AcademicWorkflow $workflow, ?int $record = null): RedirectResponse
    {
        InstitutionAccess::authorize($institution, AcademicWorkflow::permission($type));
        $model = AcademicWorkflow::TYPES[$type];
        $editing = $record ? $model::where('institution_id', $institution->id)->findOrFail($record) : new $model;
        $data = $workflow->validate($request, $institution, $type, $record);
        DB::transaction(function () use ($editing, $data, $institution): void {
            $editing->fill($data + ['institution_id' => $institution->id])->save();
            Audit::record('academic.record_saved', $editing, $institution->id, ['kind' => class_basename($editing)]);
        });

        return redirect()->route('academic.index', [$institution, $type])->with('status', 'Academic record saved.');
    }

    public function assignments(Institution $institution, AcademicClass $class): View
    {
        InstitutionAccess::authorize($institution, 'academic.classes');
        abort_unless($class->institution_id === $institution->id, 404);

        return view('institution.assignments', compact('institution', 'class') + [
            'members' => Membership::with('user', 'role')->where('institution_id', $institution->id)->where('status', 'active')->get(),
            'assignments' => DB::table('class_assignments')->where('academic_class_id', $class->id)->get(),
        ]);
    }

    public function assign(Request $request, Institution $institution, AcademicClass $class): RedirectResponse
    {
        InstitutionAccess::authorize($institution);
        abort_unless($class->institution_id === $institution->id, 404);
        $assignmentType = $request->validate(['assignment' => ['required', Rule::in(['student', 'lecturer', 'mentor', 'class_representative', 'group_leader'])]]);
        InstitutionAccess::authorize($institution, in_array($assignmentType['assignment'], ['lecturer', 'mentor'], true) ? 'academic.assign_staff' : 'academic.assign_students');
        $data = $request->validate(['membership_id' => ['required', 'integer', Rule::exists('memberships', 'id')->where('institution_id', $institution->id)->where('status', 'active')],
            'assignment' => ['required', Rule::in(['student', 'lecturer', 'mentor', 'class_representative', 'group_leader'])]]);
        $staff = in_array($data['assignment'], ['lecturer', 'mentor'], true);
        InstitutionAccess::authorize($institution, $staff ? 'academic.assign_staff' : 'academic.assign_students');
        $member = Membership::with('role')->findOrFail($data['membership_id']);
        $role = $member->role->name;
        abort_unless($staff ? in_array($role, ['Lecturer / Teacher', 'Mentor / Supervisor', 'Institution Administrator'], true) : $role === 'Student', 422, 'The contextual assignment must match the member’s institutional role.');
        DB::transaction(function () use ($data, $institution, $class): void {
            DB::table('class_assignments')->updateOrInsert(['academic_class_id' => $class->id, 'membership_id' => $data['membership_id']], ['institution_id' => $institution->id, 'assignment' => $data['assignment'], 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('academic.assignment_saved', $class, $institution->id, $data);
        });

        return back()->with('status', 'Class assignment saved.');
    }

    public function unassign(Institution $institution, AcademicClass $class, int $assignment): RedirectResponse
    {
        InstitutionAccess::authorize($institution);
        abort_unless($class->institution_id === $institution->id, 404);
        $record = DB::table('class_assignments')->where('academic_class_id', $class->id)->where('id', $assignment)->firstOrFail();
        InstitutionAccess::authorize($institution, in_array($record->assignment, ['lecturer', 'mentor'], true) ? 'academic.assign_staff' : 'academic.assign_students');
        DB::transaction(function () use ($class, $institution, $assignment): void {
            DB::table('class_assignments')->where('id', $assignment)->delete();
            Audit::record('academic.assignment_removed', $class, $institution->id, ['assignment_id' => $assignment]);
        });

        return back()->with('status', 'Assignment removed.');
    }
}
