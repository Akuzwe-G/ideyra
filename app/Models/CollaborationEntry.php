<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollaborationEntry extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('removed_at')->orderBy('created_at');
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'due_at' => 'date', 'completed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CollaborationWorkspace::class, 'collaboration_workspace_id');
    }
}
