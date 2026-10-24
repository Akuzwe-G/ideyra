<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public static function values(?int $institutionId = null): array
    {
        return static::where('scope', $institutionId ? 'institution:'.$institutionId : 'platform')->first()?->settings ?? [];
    }
}
