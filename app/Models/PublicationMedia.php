<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationMedia extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $table = 'publication_media';

    protected function casts(): array
    {
        return ['approved' => 'boolean', 'bytes' => 'integer'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(NetworkPublication::class, 'network_publication_id');
    }
}
