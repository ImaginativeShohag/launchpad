<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MakeAdmin extends Command
{
    protected $signature = 'launchpad:make-admin {email : Email of an existing, confirmed member}';

    protected $description = 'Promote an existing member to admin (the only way to create an admin)';

    public function handle(): int
    {
        $argument = $this->argument('email');
        $email = is_string($argument) ? User::normaliseEmail($argument) : null;

        if ($email === null || $email === '') {
            $this->error('Give the email address of an existing member.');

            return self::FAILURE;
        }

        // Same global lock order as ChangeUserRole/DeleteAccount: the admin set first, then the row.
        return DB::transaction(function () use ($email): int {
            User::query()->where('role', Role::Admin->value)->whereNull('deleted_at')
                ->orderBy('id')->lockForUpdate()->pluck('id');

            $user = User::query()->where('email', $email)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($user === null) {
                $this->error('No member with that email address.');

                return self::FAILURE;
            }

            if ($user->email_verified_at === null) {
                $this->error('That member has not confirmed their email address yet.');

                return self::FAILURE;
            }

            if ($user->isSuspended()) {
                $this->error('That member is suspended.');

                return self::FAILURE;
            }

            if ($user->role === Role::Admin) {
                $this->info('That member is already an admin.');

                return self::SUCCESS;
            }

            $before = $user->role;
            $user->forceFill(['role' => Role::Admin])->save();
            Audit::record('user.role_changed', null, $user, ['before' => $before->value, 'after' => 'admin', 'via' => 'artisan']);

            $this->info("{$user->handle} is now an admin.");

            return self::SUCCESS;
        });
    }
}
