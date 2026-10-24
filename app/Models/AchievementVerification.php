<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AchievementVerification extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProfessionalEntry::class, 'professional_entry_id');
    }

    public function showcase(): BelongsTo
    {
        return $this->belongsTo(ProjectShowcase::class, 'project_showcase_id');
    }

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'approved_content' => 'encrypted:array', 'verified_at' => 'datetime', 'revoked_at' => 'datetime', 'public_allowed' => 'boolean'];
    }
}
