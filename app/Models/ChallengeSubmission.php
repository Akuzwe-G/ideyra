<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChallengeSubmission extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'version' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpportunityApplication::class, 'opportunity_application_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(InnovationChallenge::class, 'innovation_challenge_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ChallengeEvaluation::class, 'challenge_submission_id');
    }
}
