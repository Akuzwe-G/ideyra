<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function academicClass(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'group_id');
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupervisionNote::class)->latest('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->latest('id');
    }

    public function progress(): array
    {
        $tasks = $this->tasks_count ?? $this->tasks()->count();
        $done = $this->done_count ?? $this->tasks()->where('status', 'completed')->count();
        $deliverables = $this->deliverables_count ?? $this->deliverables()->count();
        $approved = $this->approved_count ?? $this->deliverables()->where('status', 'approved')->count();

        return ['percent' => ($tasks + $deliverables) ? (int) round(($done + $approved) / ($tasks + $deliverables) * 100) : 0, 'tasks' => $tasks, 'done' => $done, 'deliverables' => $deliverables, 'approved' => $approved];
    }

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'target_at' => 'date', 'completed_at' => 'datetime'];
    }
}
