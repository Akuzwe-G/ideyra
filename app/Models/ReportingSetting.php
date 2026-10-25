<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['require_kpi_approval' => 'boolean'];
    }
}
