<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SponsorContact extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'email' => 'encrypted', 'phone' => 'encrypted', 'verification_evidence' => 'encrypted', 'verified_at' => 'datetime'];
    }
}
