<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpportunityApplication extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'group_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ApplicationReview::class, 'application_id')->latest('id');
    }

    protected function casts(): array
    {
        return ['career_selection' => 'encrypted:array', 'participant_ids' => 'array', 'participant_snapshot' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'external_self_reported' => 'boolean'];
    }
}
