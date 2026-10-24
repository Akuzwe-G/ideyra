<?php

namespace App\Support;

use App\Models\AcademicClass;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\ProjectGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GroupWorkflow
{
    public function save(array $data, ?ProjectGroup $group = null): ProjectGroup
    {
        $user = auth()->user();
        $institution = Institution::findOrFail($data['institution_id']);
        if ($group) {
            Gate::authorize('update', $group);
            abort_unless($group->institution_id === $institution->id, 422);
            abort_if($group->status === 'archived', 422, 'Archived groups are read-only.');
            abort_if((int) $data['academic_class_id'] !== (int) $group->academic_class_id && $group->projects()->exists(), 422, 'Groups with projects retain their academic context.');
        } else {
            abort_unless(InnovationAccess::can($institution, 'groups.create', $user), 403);
        }
        $class = AcademicClass::where('institution_id', $institution->id)->findOrFail($data['academic_class_id']);
        $members = array_map('intval', $data['member_ids'] ?? []);
        sort($members);
        $supervisors = array_map('intval', $data['supervisor_ids'] ?? []);
        sort($supervisors);
        $leader = empty($data['leader_id']) ? null : (int) $data['leader_id'];
        abort_if($leader && ! in_array($leader, $members, true), 422, 'The leader must also be a selected group member.');
        abort_if($data['status'] === 'active' && (! $leader || ! $members), 422, 'An active group requires members and a leader.');
        if ($group && $group->status === 'completed') {
            abort_unless(in_array($data['status'], ['completed', 'archived'], true), 422);
        }
        if (! $group) {
            abort_unless(in_array($data['status'], ['draft', 'active'], true), 422);
        }
        foreach ($members as $id) {
            abort_unless(Membership::whereKey($id)->where('institution_id', $institution->id)->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->whereHas('role', fn ($q) => $q->where('name', 'Student'))
                ->whereIn('id', DB::table('class_assignments')->where('institution_id', $institution->id)->where('academic_class_id', $class->id)->select('membership_id'))->exists(), 422, 'Members must be active students assigned to the selected class.');
        }
        foreach ($supervisors as $id) {
            $member = Membership::with('user', 'role')->where('institution_id', $institution->id)->findOrFail($id);
            abort_unless(in_array($member->role->name, ['Lecturer / Teacher', 'Mentor / Supervisor', 'Institution Administrator'], true) && InnovationAccess::can($institution, 'projects.supervise', $member->user), 422, 'Supervisors require active membership and supervision authority.');
        }
        $oldMembers = $group ? $group->members()->pluck('membership_id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [];
        $oldSupervisors = $group ? $group->supervisors()->pluck('memberships.id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [];
        $oldLeader = $group ? $group->members()->where('role', 'leader')->first() : null;
        $actor = InstitutionAccess::membership($institution, $user);
        $can = fn ($key) => $group ? InnovationAccess::groupManage($group, $user, $key) : InnovationAccess::can($institution, $key, $user);
        if ($members !== $oldMembers && ! (! $group && $members === [$actor?->id])) {
            abort_unless($can('groups.assign_members'), 403);
        }
        if ($leader !== ($oldLeader?->membership_id)) {
            abort_unless($can('groups.assign_leaders'), 403);
        }
        if ($supervisors !== $oldSupervisors) {
            abort_unless($can('groups.assign_supervisors'), 403);
        }
        $delegated = $data['delegated_permissions'] ?? [];
        if ($delegated !== ($oldLeader?->delegated_permissions ?? [])) {
            abort_unless($can('groups.assign_leaders'), 403);
            foreach ($delegated as $key) {
                abort_unless(InnovationAccess::can($institution, $key, $user), 403, 'You cannot delegate authority you do not hold.');
            }
        }

        return DB::transaction(function () use ($data, $group, $institution, $members, $supervisors, $leader, $delegated, $oldMembers, $oldSupervisors): ProjectGroup {
            Institution::whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $group ??= new ProjectGroup(['identifier' => (string) Str::ulid(), 'institution_id' => $institution->id, 'created_by' => auth()->id()]);
            $group->fill(collect($data)->only(['name', 'description', 'academic_class_id', 'status'])->all());
            $group->save();
            $group->members()->delete();
            foreach ($members as $id) {
                $group->members()->create(['institution_id' => $institution->id, 'membership_id' => $id, 'role' => $id === $leader ? 'leader' : 'member', 'delegated_permissions' => $id === $leader ? $delegated : []]);
            }
            $group->supervisors()->sync(collect($supervisors)->mapWithKeys(fn ($id) => [$id => ['institution_id' => $institution->id]])->all());
            Audit::record('project_group.membership_changed', $group, $institution->id, ['member_ids' => $members, 'leader_id' => $leader, 'supervisor_ids' => $supervisors]);
            $newIds = Membership::whereIn('id', array_merge(array_diff($members, $oldMembers), array_diff($supervisors, $oldSupervisors)))->pluck('user_id')->all();
            ProjectSignals::send($newIds, 'group', $group, 'Academic group assignment', 'You have a new academic group or supervision assignment.');

            return $group;
        });
    }
}
