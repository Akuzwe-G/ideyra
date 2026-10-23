<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    protected function casts(): array
    {
        return ['locked_fields' => 'array', 'approved_at' => 'datetime'];
    }
}
