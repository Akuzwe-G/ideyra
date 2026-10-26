<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CollaborationRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'submitted_at' => 'datetime', 'representative_accepted_at' => 'datetime', 'supporting_snapshot' => 'encrypted:array', 'motivation' => 'encrypted', 'skills' => 'encrypted', 'contribution' => 'encrypted'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(NetworkPublication::class, 'network_publication_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function workspace(): HasOne
    {
        return $this->hasOne(CollaborationWorkspace::class, 'collaboration_request_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CollaborationApproval::class, 'collaboration_request_id');
    }
}
