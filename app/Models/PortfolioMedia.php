<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioMedia extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function showcase(): BelongsTo
    {
        return $this->belongsTo(ProjectShowcase::class, 'project_showcase_id');
    }

    protected function casts(): array
    {
        return ['public_approved' => 'boolean'];
    }
}
