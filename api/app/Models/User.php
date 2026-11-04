<?php

namespace App\Models;

use App\Enums\Role;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Role $role
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $pending_email_expires_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, RoutesNotifications;

    /** Role, suspension, risk and verification fields are never mass-assigned. */
    protected $fillable = ['handle', 'name', 'email', 'password', 'headline'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'risk_score' => 'integer',
        ];
    }

    /** Emails are stored lowercase and trimmed; use this for every lookup too (mutators do not run on where()). */
    public static function normaliseEmail(?string $email): ?string
    {
        return $email === null ? null : Str::lower(trim($email));
    }

    /** @return Attribute<string|null, string|null> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normaliseEmail($value));
    }

    /** @return Attribute<string|null, string|null> */
    protected function handle(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : Str::lower(trim($value)));
    }

    /** Public profiles are addressed by handle; deleted accounts never resolve. */
    public function getRouteKeyName(): string
    {
        return 'handle';
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        // A handle is short, valid UTF-8 and has no NUL byte; anything else cannot exist (and must not reach the query).
        if (! is_string($value) || $value === '' || strlen($value) > 64 || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), strtolower($value))
            ->whereNull('deleted_at')
            ->first();
    }

    /** Public URL of the avatar on the configured disk, or null. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? Storage::disk((string) config('launchpad.avatar_disk'))->url($this->avatar_path)
            : null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Neither suspended nor deleted: the only accounts that may act or be recognised on public routes. */
    public function isAvailable(): bool
    {
        return ! $this->isSuspended() && ! $this->isDeleted();
    }

    /** @return HasMany<UserLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(UserLink::class)->orderBy('position');
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'maker_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
