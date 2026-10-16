<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Roles per landlord account: built-in templates (shared, locked here) + the account's own roles. */
class RolesApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_lists_templates_and_the_permission_catalogue(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Owner')
            ->assertJsonPath('data.0.is_locked', true)
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('data.2.name', 'Caretaker')
            ->assertJsonPath('data.2.users_count', 1);

        $groups = $this->getJson('/api/admin/permissions')->assertOk()->json('data');
        $this->assertSame('Dashboard', $groups[0]['group']);
        $this->assertContains('readings.record', collect($groups)->flatMap(fn ($g) => array_column($g['permissions'], 'name'))->all());
    }

    public function test_custom_roles_create_edit_delete(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/roles', ['name' => 'Meter reader', 'permissions' => ['readings.view', 'readings.record']])->assertAccepted();
        $this->postJson('/api/admin/roles', ['name' => 'Meter reader', 'permissions' => []])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/roles', ['name' => 'Owner', 'permissions' => []])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/roles', ['name' => 'X', 'permissions' => ['launch.rockets']])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');

        $role = Role::where('name', 'Meter reader')->firstOrFail();
        $this->assertSame($this->kims->id, $role->account_id);

        $this->putJson("/api/admin/roles/{$role->id}", ['name' => 'Readings', 'permissions' => ['readings.view']])->assertAccepted();
        $this->assertSame(['readings.view'], $role->fresh()->permissions->pluck('name')->all());

        // Can't delete while someone has it.
        $staff = User::factory()->withRole($role->fresh())->create(['account_id' => $this->kims->id]);
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertUnprocessable()->assertJsonValidationErrors('role');
        app(CurrentAccount::class)->actingAs($this->kims, fn () => $staff->syncRoles([]));
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertAccepted();
        $this->assertNull(Role::find($role->id));
    }

    public function test_templates_are_locked_here_and_other_accounts_roles_are_invisible(): void
    {
        $this->actingAsAdmin();
        $caretaker = app(PermissionCatalog::class)->template(SystemRole::Caretaker);

        $this->putJson("/api/admin/roles/{$caretaker->id}", ['name' => 'Caretaker', 'permissions' => []])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->deleteJson("/api/admin/roles/{$caretaker->id}")->assertUnprocessable();

        $other = Account::factory()->create();
        $foreign = Role::create(['name' => 'Their role', 'guard_name' => 'web', 'account_id' => $other->id]);
        $this->assertNotContains('Their role', array_column($this->getJson('/api/admin/roles')->json('data'), 'name'));
        $this->putJson("/api/admin/roles/{$foreign->id}", ['name' => 'Mine', 'permissions' => []])->assertNotFound();
    }

    public function test_only_role_managers_change_roles(): void
    {
        $this->actingAsAdmin('caretaker@rentflow.test');

        $this->getJson('/api/admin/roles')->assertForbidden(); // no team.view
        $this->postJson('/api/admin/roles', ['name' => 'Mine', 'permissions' => []])->assertForbidden();
    }
}
