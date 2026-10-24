<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['messages' => 'encrypted:array', 'insights' => 'encrypted:array', 'consented_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
