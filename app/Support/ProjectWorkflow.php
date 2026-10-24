<?php

namespace App\Support;

use App\Models\AcademicClass;
use App\Models\Idea;
use App\Models\Institution;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectWorkflow
{
    public static function activity(Project $project, string $action, array $metadata = []): void
    {
        $project->activities()->create(['actor_id' => auth()->id(), 'action' => $action, 'metadata' => $metadata]);
    }

    public static function writable(Project $project): void
    {
        abort_if(in_array($project->status, ['completed', 'cancelled', 'archived'], true), 422, 'This project is closed. Its history remains available.');
    }

    public function save(array $data, ?Project $project = null): Project
    {
        $user = auth()->user();
        $institution = empty($data['institution_id']) ? null : Institution::findOrFail($data['institution_id']);
        if ($project) {
            Gate::authorize('update', $project);
            self::writable($project);
            abort_unless((int) $project->institution_id === (int) ($institution?->id) && (int) $project->idea_id === (int) ($data['idea_id'] ?? 0), 422, 'Project ownership, origin and workspace cannot be transferred.');
        } elseif ($institution) {
            abort_unless(InnovationAccess::can($institution, 'projects.create', $user) && InnovationAccess::can($institution, 'projects.create_institutional', $user) && InnovationAccess::can($institution, 'projects.view_assigned', $user), 403, 'Institutional creation requires explicit project creation authority.');
        }
        $group = empty($data['group_id']) ? null : ProjectGroup::findOrFail($data['group_id']);
        $class = empty($data['academic_class_id']) ? null : AcademicClass::findOrFail($data['academic_class_id']);
        if ($group) {
            abort_unless($institution && $group->institution_id === $institution->id && InnovationAccess::groups($user)->whereKey($group->id)->exists(), 422, 'Choose an authorised group in this institution.');
            abort_unless(in_array($group->status, ['draft', 'active'], true), 422, 'Choose an open group.');
            if ($class) {
                abort_unless($class->id === $group->academic_class_id, 422, 'Group and academic class must match.');
            }
            $class = $group->academicClass;
        }
        if ($class) {
            abort_unless($institution && $class->institution_id === $institution->id, 422, 'Academic context must belong to this institution.');
        }
        $idea = empty($data['idea_id']) ? null : Idea::findOrFail($data['idea_id']);
        if ($idea && ! $project) {
            Gate::authorize('view', $idea);
            abort_unless($idea->owner_id === $user->id, 403, 'Only the idea owner may convert it.');
            if ($idea->institution_id) {
                abort_unless($institution?->id === $idea->institution_id && $idea->status === 'approved', 422, 'Institutional ideas require approval and must remain in their institution.');
            } elseif ($institution) {
                abort_unless(! empty($data['confirm_share']) && InnovationAccess::can($institution, 'ideas.share', $user), 422, 'Explicit permission and consent are required to copy a personal idea into institutional content.');
            }
        }

        return DB::transaction(function () use ($data, $project, $institution, $class, $group, $idea, $user): Project {
            if ($idea && ! $project) {
                Idea::whereKey($idea->id)->lockForUpdate()->firstOrFail();
                if (Project::where('idea_id', $idea->id)->exists()) {
                    throw ValidationException::withMessages(['idea_id' => 'This idea already has a linked project. Open it from the idea detail page.']);
                }
            }
            $creating = ! $project;
            $project ??= new Project(['identifier' => (string) Str::ulid(), 'owner_id' => $user->id, 'institution_id' => $institution?->id, 'idea_id' => $idea?->id, 'status' => 'draft']);
            $project->fill(collect($data)->only(['title', 'description', 'problem', 'objectives', 'outcomes', 'category', 'starts_at', 'target_at'])->all());
            $project->group_id = $group?->id;
            $project->academic_class_id = $class?->id;
            $project->save();
            self::activity($project, $creating ? 'project.created' : 'project.details_updated');

            return $project;
        });
    }

    public function transition(Project $project, string $status, ?string $reason): void
    {
        if ($status === 'completed' && $project->institution_id) {
            Gate::authorize('review', $project);
        } else {
            Gate::authorize('update', $project);
        }
        if ($status === 'archived' && $project->institution_id) {
            abort_unless(InnovationAccess::can($project->institution, 'projects.archive', auth()->user()), 403);
        }
        DB::transaction(function () use ($project, $status, $reason): void {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $transitions = [
                'draft' => $project->institution_id ? ['pending_approval', 'cancelled', 'archived'] : ['in_progress', 'cancelled', 'archived'],
                'pending_approval' => ['draft', 'cancelled'],
                'approved' => ['in_progress', 'on_hold', 'cancelled', 'archived'],
                'in_progress' => ['on_hold', 'completed', 'cancelled'],
                'on_hold' => ['in_progress', 'cancelled'],
                'completed' => ['archived'], 'cancelled' => ['archived'], 'archived' => [],
            ];
            abort_unless(in_array($status, $transitions[$project->status] ?? [], true), 422, 'This project status transition is not allowed.');
            if ($status === 'completed') {
                abort_unless($reason, 422, 'A final completion decision requires a reason.');
                abort_if($project->tasks()->where('status', '!=', 'completed')->exists() || $project->deliverables()->where('status', '!=', 'approved')->exists() || $project->milestones()->where('status', '!=', 'completed')->exists(), 422, 'Complete all milestones and tasks and approve all deliverables first.');
                DB::table('project_decisions')->insert(['project_id' => $project->id, 'reviewer_id' => auth()->id(), 'decision' => 'completed', 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            }
            $project->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : $project->completed_at]);
            self::activity($project, 'project.status_changed', ['status' => $status]);
            ProjectSignals::send(InnovationAccess::participants($project)->pluck('id')->all(), 'project', $project, 'Project status updated', 'A project in your authorised workspace has a new status.');
        });
    }

    public function review(Project $project, string $decision, string $reason): void
    {
        Gate::authorize('review', $project);
        DB::transaction(function () use ($project, $decision, $reason): void {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($project->status === 'pending_approval', 422, 'Only projects awaiting approval can be reviewed.');
            DB::table('project_decisions')->insert(['project_id' => $project->id, 'reviewer_id' => auth()->id(), 'decision' => $decision, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            $project->update(['status' => match ($decision) {
                'approved' => 'approved','returned' => 'draft',default => 'cancelled'
            }]);
            self::activity($project, 'project.reviewed', ['decision' => $decision]);
            ProjectSignals::send(InnovationAccess::participants($project)->pluck('id')->all(), 'project', $project, 'Project decision recorded', 'Your project has an approval, revision or rejection decision.');
        });
    }

    public function team(Project $project, array $data): void
    {
        Gate::authorize('update', $project);
        self::writable($project);
        $user = ! empty($data['user_id']) ? User::findOrFail($data['user_id']) : User::where('email', mb_strtolower(trim($data['email'] ?? '')))->first();
        abort_unless($user && $user->status === 'active' && $user->id !== $project->owner_id, 422, 'Choose another active identity.');
        $membership = $project->institution_id ? InstitutionAccess::membership($project->institution, $user) : null;
        if ($project->institution_id) {
            abort_unless($membership && InnovationAccess::can($project->institution, 'groups.assign_members', auth()->user()), 403);
            if ($data['role'] === 'supervisor') {
                abort_unless(InnovationAccess::can($project->institution, 'groups.assign_supervisors', auth()->user()) && in_array($membership->role->name, ['Lecturer / Teacher', 'Mentor / Supervisor', 'Institution Administrator'], true) && InnovationAccess::can($project->institution, 'projects.supervise', $user), 403);
            }
            if ($data['role'] === 'leader') {
                abort_unless(InnovationAccess::can($project->institution, 'groups.assign_leaders', auth()->user()), 403);
            }
        }
        DB::transaction(function () use ($project, $user, $membership, $data): void {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $old = $project->members()->where('user_id', $user->id)->first();
            $project->members()->updateOrCreate(['user_id' => $user->id], ['institution_id' => $project->institution_id, 'membership_id' => $membership?->id, 'role' => $data['role']]);
            self::activity($project, 'project.team_updated', ['user_id' => $user->id, 'role' => $data['role']]);
            if (! $old || $old->role !== $data['role']) {
                ProjectSignals::send([$user->id], 'project', $project, 'Project assignment', 'You have a new project team or supervisor assignment.');
            }
        });
    }

    public function removeTeam(Project $project, ProjectMember $member): void
    {
        Gate::authorize('update', $project);
        self::writable($project);
        abort_unless($member->project_id === $project->id, 404);
        if ($project->institution_id) {
            abort_unless(InnovationAccess::can($project->institution, $member->role === 'supervisor' ? 'groups.assign_supervisors' : 'groups.assign_members', auth()->user()), 403);
        }
        $member->delete();
        self::activity($project, 'project.member_removed', ['user_id' => $member->user_id]);
    }
}
