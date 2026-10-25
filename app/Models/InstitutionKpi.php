<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstitutionKpi extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'period_from' => 'date', 'period_to' => 'date', 'target' => 'float', 'target_upper' => 'float'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(KpiChange::class)->latest('id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(KpiObservation::class)->orderByDesc('observed_on')->orderByDesc('id');
    }
}
