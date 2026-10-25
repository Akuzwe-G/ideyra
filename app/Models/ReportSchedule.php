<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'recipient_ids' => 'array', 'next_run_at' => 'datetime', 'enabled' => 'boolean'];
    }
}
