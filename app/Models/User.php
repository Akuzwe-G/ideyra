<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public function initials(): string
    {
        return collect(preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'I';
    }

    public function sendPasswordResetNotification(mixed $token): void
    {
        if (config('mail.mailers.'.config('mail.default').'.transport') === 'log') {
            $notification = new ResetPassword($token);
            $path = 'password-reset-handoffs/'.Str::uuid().'.txt';
            Storage::disk('local')->put($path, $notification->toMail($this)->actionUrl);
            @chmod(Storage::disk('local')->path($path), 0600);

            return;
        }
        $this->notify(new ResetPassword($token));
    }

    protected $attributes = ['status' => 'active'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
