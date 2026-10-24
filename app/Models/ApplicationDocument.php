<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpportunityApplication::class, 'application_id');
    }
}
