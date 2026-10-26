<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkRecognition extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'evidence' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(AchievementVerification::class, 'achievement_verification_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProfessionalEntry::class, 'professional_entry_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
