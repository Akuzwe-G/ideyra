<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectShowcase extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class, 'professional_profile_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PortfolioMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(AchievementVerification::class);
    }

    protected function casts(): array
    {
        return ['links' => 'array'];
    }
}
