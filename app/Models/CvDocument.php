<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CvDocument extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function snapshots(): HasMany
    {
        return $this->hasMany(CvSnapshot::class)->latest('version');
    }

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'sections' => 'array'];
    }
}
