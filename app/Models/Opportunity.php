<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OpportunityCategory::class, 'category_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'opportunity_managers');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(OpportunityApplication::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'published' && (! $this->opens_at || $this->opens_at->lte(now())) && (! $this->deadline_at || $this->deadline_at->gte(now()));
    }

    protected function casts(): array
    {
        return ['eligibility' => 'array', 'opens_at' => 'datetime', 'deadline_at' => 'datetime', 'event_starts_at' => 'date', 'event_ends_at' => 'date', 'published_at' => 'datetime', 'allow_groups' => 'boolean', 'notify_publication' => 'boolean'];
    }
}
