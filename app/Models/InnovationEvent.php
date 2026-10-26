<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InnovationEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'agenda' => 'array', 'published_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(InnovationChallenge::class, 'innovation_challenge_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'innovation_event_id');
    }

    public function exhibits(): HasMany
    {
        return $this->hasMany(ExhibitionProject::class, 'innovation_event_id');
    }
}
