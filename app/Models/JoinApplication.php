<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JoinApplication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['payload', 'review_notes', 'submission_hash', 'duplicate_hash', 'search_tokens', 'institution_tokens'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'review_notes' => 'encrypted:array', 'search_tokens' => 'array', 'institution_tokens' => 'array', 'decided_at' => 'datetime', 'mail_attempted_at' => 'datetime'];
    }
}
