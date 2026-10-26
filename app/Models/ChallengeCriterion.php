<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeCriterion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(InnovationChallenge::class, 'innovation_challenge_id');
    }
}
