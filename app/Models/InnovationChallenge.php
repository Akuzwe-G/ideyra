<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InnovationChallenge extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allow_cross_institution' => 'boolean', 'results_snapshot' => 'encrypted:array', 'results_published_at' => 'datetime', 'event_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(ChallengeCriterion::class, 'innovation_challenge_id');
    }

    public function judges(): HasMany
    {
        return $this->hasMany(ChallengeJudge::class, 'innovation_challenge_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ChallengeSubmission::class, 'innovation_challenge_id');
    }

    public function event(): HasOne
    {
        return $this->hasOne(InnovationEvent::class, 'innovation_challenge_id');
    }
}
