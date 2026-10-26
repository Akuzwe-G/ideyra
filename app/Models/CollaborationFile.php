<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollaborationFile extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CollaborationWorkspace::class, 'collaboration_workspace_id');
    }
}
