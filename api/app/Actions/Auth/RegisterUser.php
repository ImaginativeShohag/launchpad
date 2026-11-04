<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterUser
{
    /** @param array{name: string, email: string, password: string, handle: string} $data */
    public function handle(array $data): User
    {
        try {
            return $this->create($data);
        } catch (UniqueConstraintViolationException) {
            // Two requests passed validation at once. The failed transaction has rolled back by now,
            // so ask the database which fields clash (no parsing of driver messages).
            $errors = [];
            if (User::query()->where('email', User::normaliseEmail($data['email']))->exists()) {
                $errors['email'] = 'The email has already been taken.';
            }
            if (User::query()->where('handle', Str::lower($data['handle']))->exists()) {
                $errors['handle'] = 'This handle is already taken.';
            }

            throw ValidationException::withMessages($errors ?: ['email' => 'The email has already been taken.']);
        }
    }

    /** @param array{name: string, email: string, password: string, handle: string} $data */
    private function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'handle' => $data['handle'],
            ]);

            // Framework listener queues the confirmation mail (after commit);
            // RecordAuthEvent writes the signup row.
            event(new Registered($user));

            // Reload so DB-default columns (role, risk_score, ...) are present under strict mode.
            return $user->refresh();
        });
    }
}
