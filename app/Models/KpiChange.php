<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiChange extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous' => 'array', 'proposed' => 'array', 'decided_at' => 'datetime'];
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(InstitutionKpi::class, 'institution_kpi_id');
    }
}
