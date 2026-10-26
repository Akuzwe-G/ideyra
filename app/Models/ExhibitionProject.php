<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExhibitionProject extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['presentation_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(NetworkPublication::class, 'network_publication_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(InnovationEvent::class, 'innovation_event_id');
    }
}
