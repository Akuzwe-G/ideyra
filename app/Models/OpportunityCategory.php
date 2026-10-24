<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpportunityCategory extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
