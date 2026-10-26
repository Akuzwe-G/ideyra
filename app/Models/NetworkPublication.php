<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkPublication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft' => 'array', 'approved_snapshot' => 'encrypted:array', 'version' => 'integer', 'approved_version' => 'integer', 'published_at' => 'datetime', 'collaboration_open' => 'boolean', 'institution_attribution' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PublicationMedia::class, 'network_publication_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PublicationConsent::class, 'network_publication_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PublicationDecision::class, 'network_publication_id');
    }
}
