<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'group_id');
    }

    protected function casts(): array
    {
        return ['delegated_permissions' => 'array'];
    }
}
