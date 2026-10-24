<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicClass extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
