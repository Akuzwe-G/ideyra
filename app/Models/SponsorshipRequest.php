<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SponsorshipRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SponsorContact::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(SponsorshipCommitment::class);
    }

    protected function casts(): array
    {
        return ['estimated_value' => 'decimal:2', 'needed_from' => 'date', 'needed_until' => 'date'];
    }
}
