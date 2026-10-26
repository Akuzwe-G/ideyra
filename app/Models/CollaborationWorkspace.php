<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollaborationWorkspace extends Model
{
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'institution_ids' => 'array', 'purpose' => 'encrypted'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CollaborationRequest::class, 'collaboration_request_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(NetworkPublication::class, 'network_publication_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CollaborationMember::class, 'collaboration_workspace_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CollaborationEntry::class, 'collaboration_workspace_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CollaborationFile::class, 'collaboration_workspace_id');
    }
}
