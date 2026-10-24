<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipCommitment extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(SponsorContact::class, 'sponsor_contact_id');
    }

    protected function casts(): array
    {
        return ['pledged_value' => 'decimal:2', 'fulfilled_at' => 'datetime', 'fulfilment_evidence' => 'encrypted'];
    }
}
