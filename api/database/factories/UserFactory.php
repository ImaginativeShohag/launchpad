<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'handle' => 'u'.Str::lower(Str::random(10)),
            'name' => fake()->name(),
            'email' => Str::lower((string) Str::ulid()).'@example.test',
            'email_verified_at' => now(),
            'password' => 'correct-horse-battery',
            // Set explicitly: a freshly created model does not read DB defaults back,
            // and resources/policies read ->role on the very same instance.
            'role' => Role::Member,
            // Likewise explicit, so middleware reading them works on factory-built models under strict mode.
            'suspended_at' => null,
            'suspended_reason' => null,
            'deleted_at' => null,
            'pending_email' => null,
            'pending_email_expires_at' => null,
            'headline' => null,
            'avatar_path' => null,
            'risk_score' => 0,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function withoutPassword(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now(), 'suspended_reason' => 'test']);
    }

    public function lastLoginMinutesAgo(int $minutes): static
    {
        return $this->state(fn () => ['last_login_at' => now()->subMinutes($minutes)]);
    }
}
