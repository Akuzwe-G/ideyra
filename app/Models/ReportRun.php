<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'access_scope' => 'array', 'recipient_ids' => 'array', 'snapshot' => 'encrypted:array', 'expires_at' => 'datetime', 'generated_at' => 'datetime', 'scheduled_for' => 'datetime'];
    }
}
