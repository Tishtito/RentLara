<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Mail\AdminPasswordLinkMail;
use App\Models\Account;
use App\Models\Property;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Team management: invite → set password → logged in with the right permissions; guards. */
class TeamApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/set-password'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
    }

    public function test_invite_set_password_and_log_in_with_the_role(): void
    {
        $owner = $this->actingAsAdmin();
        $role = $this->customRole('Meter reader', ['readings.view', 'readings.record', 'properties.view']);
        $property = $this->asKims(fn () => Property::firstOrFail());

        $this->postJson('/api/admin/team', ['name' => 'Joseph Kariuki', 'email' => 'Joseph@Example.com', 'phone' => '0712 111 222', 'role_ids' => [$role->id], 'property_ids' => [$property->id]])
            ->assertAccepted();

        $link = null;
        Mail::assertSent(AdminPasswordLinkMail::class, function (AdminPasswordLinkMail $m) use (&$link) {
            $link = $m->link;
            $html = $m->render();

            return $m->invitation && $m->hasTo('joseph@example.com') && str_contains($html, 'Kims Properties') && str_contains($html, 'Set up my login');
        });
        parse_str(parse_url($link, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('http://localhost:5173/set-password?', $link);

        $this->getJson('/api/admin/team')->assertOk()->assertJsonFragment(['email' => 'joseph@example.com', 'status' => 'invited']);
        $this->assertSame($property->caretaker_id === null ? null : User::where('email', 'joseph@example.com')->value('id'), $property->fresh()->caretaker_id);

        // Can't log in before setting a password.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/auth/login', ['email' => 'joseph@example.com', 'password' => 'whatever'], self::SPA)->assertStatus(422);

        $this->postJson('/api/admin/auth/set-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'], self::SPA)
            ->assertOk()
            ->assertJsonPath('user.roles', ['Meter reader'])
            ->assertJsonPath('user.permissions', ['properties.view', 'readings.view', 'readings.record'])
            ->assertJsonPath('user.is_owner', false);

        // The link works once.
        $this->postJson('/api/admin/auth/set-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'x-pass-1234', 'password_confirmation' => 'x-pass-1234'], self::SPA)
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_edit_suspend_remove_and_guards(): void
    {
        $owner = $this->actingAsAdmin();
        $caretaker = User::where('email', 'caretaker@rentflow.test')->firstOrFail();
        $manager = app(PermissionCatalog::class)->template(SystemRole::Manager);
        $ownerRole = app(PermissionCatalog::class)->template(SystemRole::Owner);

        $this->putJson("/api/admin/team/{$caretaker->id}", ['name' => 'Kims Caretaker', 'role_ids' => [$manager->id]])->assertAccepted();
        $this->getJson('/api/admin/team')->assertJsonFragment(['roles' => [['id' => $manager->id, 'name' => 'Manager']]]);

        // Not yourself, and the account keeps an Owner.
        $this->putJson("/api/admin/team/{$owner->id}", ['name' => 'Kim', 'role_ids' => [$manager->id]])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->patchJson("/api/admin/team/{$owner->id}/status", ['status' => 'suspended'])->assertUnprocessable();
        $this->deleteJson("/api/admin/team/{$owner->id}")->assertUnprocessable();

        // Suspended → can't log in or keep using the API.
        $this->patchJson("/api/admin/team/{$caretaker->id}/status", ['status' => 'suspended'])->assertAccepted();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/auth/login', ['email' => 'caretaker@rentflow.test', 'password' => 'password'], self::SPA)->assertForbidden();

        // A second owner can then be the one who's demoted; the last one can't.
        $this->actingAsAdmin();
        $this->patchJson("/api/admin/team/{$caretaker->id}/status", ['status' => 'active'])->assertAccepted();
        $this->putJson("/api/admin/team/{$caretaker->id}", ['name' => 'Kims Caretaker', 'role_ids' => [$ownerRole->id]])->assertAccepted();
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->putJson("/api/admin/team/{$owner->id}", ['name' => 'Kim', 'role_ids' => [$manager->id]])->assertAccepted();
        $this->putJson("/api/admin/team/{$owner->id}", ['name' => 'Kim', 'role_ids' => [$ownerRole->id]])->assertAccepted();

        $this->deleteJson("/api/admin/team/{$owner->id}")->assertAccepted();
        $this->assertSoftDeleted('users', ['id' => $owner->id]);
    }

    public function test_validation_and_isolation(): void
    {
        $this->actingAsAdmin();
        $foreignRole = Role::create(['name' => 'Theirs', 'guard_name' => 'web', 'account_id' => Account::factory()->create()->id]);
        $foreignUser = User::factory()->create();

        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'landlord@rentflow.test', 'role_ids' => [$foreignRole->id]])->assertUnprocessable();
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'x@example.com', 'role_ids' => [$foreignRole->id]])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->postJson('/api/admin/team', ['name' => 'X', 'email' => 'x@example.com', 'role_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->putJson("/api/admin/team/{$foreignUser->id}", ['name' => 'X', 'role_ids' => [1]])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_forgot_password_sends_a_link_without_revealing_accounts(): void
    {
        $this->postJson('/api/admin/auth/forgot-password', ['email' => 'nobody@example.com'])->assertAccepted();
        Mail::assertNothingQueued();

        $this->postJson('/api/admin/auth/forgot-password', ['email' => 'landlord@rentflow.test'])->assertAccepted();
        Mail::assertQueued(AdminPasswordLinkMail::class, function (AdminPasswordLinkMail $m) {
            parse_str(parse_url($m->link, PHP_URL_QUERY), $q);
            $this->postJson('/api/admin/auth/set-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1'], self::SPA)->assertOk();

            return ! $m->invitation && str_contains($m->render(), 'Reset your password');
        });

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'brand-new-1'], self::SPA)->assertOk();
    }

    private function customRole(string $name, array $permissions): Role
    {
        $this->postJson('/api/admin/roles', ['name' => $name, 'permissions' => $permissions])->assertAccepted();

        return Role::where('name', $name)->where('account_id', $this->kims->id)->firstOrFail();
    }
}
