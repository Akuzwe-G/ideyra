<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['theme' => 'minimal', 'published' => false, 'search_visible' => false, 'contact_form_enabled' => false];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class, 'professional_profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PortfolioMessage::class)->latest('id');
    }

    protected function casts(): array
    {
        return ['contact_form_enabled' => 'boolean', 'sections' => 'array', 'contact_fields' => 'array', 'entry_ids' => 'array', 'showcase_ids' => 'array', 'payload' => 'encrypted:array', 'published' => 'boolean', 'search_visible' => 'boolean', 'published_at' => 'datetime'];
    }
}
