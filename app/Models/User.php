<?php

namespace App\Models;

use App\Enums\CodePurpose;
use App\Notifications\EmailVerificationCode;
use App\Services\UserCodeService;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, HasRoles, MustVerifyEmail, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime'];
    }

    /** Two-character initials for the avatar component. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::upper(match (count($parts)) {
            0 => '?',
            1 => Str::substr($parts[0], 0, 2),
            default => Str::substr($parts[0], 0, 1).Str::substr(end($parts), 0, 1),
        });
    }

    /** The single role this user holds. Roles live in the pivot, not a column. */
    public function role(): ?string
    {
        return $this->getRoleNames()->first();
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Laravel's default sends a signed link. This project verifies with a typed
     * code, so the override keeps the two paths from ever diverging.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = app(UserCodeService::class)->issue($this, CodePurpose::EmailVerification);

        $this->notify(new EmailVerificationCode($code));
    }
}
