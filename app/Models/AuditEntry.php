<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    use HasFactory;

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['metadata' => 'encrypted:array'];
    }
}
