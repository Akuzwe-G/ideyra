<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeEvaluation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scores' => 'encrypted:array', 'feedback' => 'encrypted', 'total' => 'float', 'finalised_at' => 'datetime'];
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ChallengeSubmission::class, 'challenge_submission_id');
    }
}
