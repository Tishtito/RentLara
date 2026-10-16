<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'role' => UserRole::Staff,
            'status' => 'active',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Platform operator: no account, sees every landlord. */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => null,
            'role' => UserRole::SuperAdmin,
        ]);
    }

    /**
     * Staff get a role in their account: Owner by default (what a landlord was), or another one with
     * caretaker() / withRole() / withoutRole(), which replace it. Super admins get none.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $this->giveRoles($user, [SystemRole::Owner]));
    }

    public function caretaker(): static
    {
        return $this->withRole(SystemRole::Caretaker);
    }

    /** @param  SystemRole|Role  $role */
    public function withRole(mixed $role): static
    {
        return $this->afterCreating(fn (User $user) => $this->giveRoles($user, [$role]));
    }

    /** No role at all (e.g. to assign roles in a test). */
    public function withoutRole(): static
    {
        return $this->afterCreating(fn (User $user) => $this->giveRoles($user, []));
    }

    /** @param  list<SystemRole|Role>  $roles */
    private function giveRoles(User $user, array $roles): void
    {
        if ($user->isSuperAdmin() || ! $user->account_id) {
            return;
        }

        app(CurrentAccount::class)->actingAs($user->account_id, fn () => $user->syncRoles(array_map(
            fn ($role) => $role instanceof SystemRole ? app(PermissionCatalog::class)->template($role) : $role,
            $roles,
        )));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
