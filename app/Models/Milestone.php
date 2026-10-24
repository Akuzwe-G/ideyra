<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milestone extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function overdue(): bool
    {
        return $this->status !== 'completed' && $this->due_at->lt(today());
    }

    protected function casts(): array
    {
        return ['due_at' => 'date', 'completed_at' => 'datetime'];
    }
}
