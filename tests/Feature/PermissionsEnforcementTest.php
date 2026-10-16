<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** What a role allows is what the API allows; super admins can manage any account's roles. */
class PermissionsEnforcementTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_custom_role_allows_exactly_its_permissions(): void
    {
        $role = $this->asKims(fn () => tap(Role::create(['name' => 'Meter reader', 'guard_name' => 'web', 'account_id' => $this->kims->id]))
            ->syncPermissions(['readings.view', 'readings.record', 'properties.view', 'properties.view_all']));
        $reader = User::factory()->withRole($role)->create(['account_id' => $this->kims->id]);
        Sanctum::actingAs($reader);
        $meter = $this->asKims(fn () => Meter::firstOrFail());
        $lease = $this->asKims(fn () => Lease::firstOrFail());
        $property = $this->asKims(fn () => Property::firstOrFail());

        $this->getJson('/api/admin/readings')->assertOk();
        $this->postJson('/api/admin/readings', ['reading_date' => '2026-11-05', 'readings' => [['meter_id' => $meter->id, 'reading' => '190']]])->assertAccepted();
        $this->getJson('/api/admin/properties')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/payments')->assertForbidden();
        $this->postJson('/api/admin/payments', ['lease_id' => $lease->id, 'amount' => '1', 'method' => 'cash', 'paid_at' => '2026-11-05 05:00:00'])->assertForbidden();
        $this->putJson("/api/admin/properties/{$property->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->getJson('/api/admin/dashboard')->assertForbidden();
        $this->getJson('/api/admin/user')->assertOk()->assertJsonPath('user.permissions', ['properties.view', 'properties.view_all', 'readings.view', 'readings.record']);
    }

    public function test_property_scope_follows_properties_view_all(): void
    {
        $this->asKims(fn () => Property::factory()->create(['account_id' => $this->kims->id, 'name' => 'Other block']));

        // Caretaker template: no view_all → only the property they look after.
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->getJson('/api/admin/properties')->assertJsonCount(1, 'data');

        // Accountant template: view_all → every property.
        $accountant = User::factory()->withRole(SystemRole::Accountant)->create(['account_id' => $this->kims->id]);
        Sanctum::actingAs($accountant);
        $this->getJson('/api/admin/properties')->assertJsonCount(2, 'data');
    }

    public function test_changing_a_template_changes_it_everywhere_and_only_super_admins_can(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $caretakerRole = app(PermissionCatalog::class)->template(SystemRole::Caretaker);
        $owner = app(PermissionCatalog::class)->template(SystemRole::Owner);

        $this->actingAsAdmin();
        $this->getJson('/api/platform/accounts')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/platform/accounts')->assertOk()->assertJsonPath('data.0.name', 'Kims Properties')->assertJsonPath('data.0.owner_name', 'Kim Landlord');
        $this->putJson("/api/platform/role-templates/{$owner->id}", ['permissions' => []])->assertUnprocessable();
        $this->putJson("/api/platform/role-templates/{$caretakerRole->id}", ['permissions' => ['dashboard.view', 'readings.view']])->assertAccepted();

        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->getJson('/api/admin/readings')->assertOk();
        $this->getJson('/api/admin/payments')->assertForbidden(); // payments.view was removed from the template
    }

    public function test_super_admin_manages_an_accounts_roles_with_x_account_id(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $headers = ['X-Account-Id' => (string) $this->kims->id];

        $this->postJson('/api/admin/roles', ['name' => 'Support', 'permissions' => ['tenants.view']], $headers)->assertAccepted();
        $this->assertSame($this->kims->id, Role::where('name', 'Support')->value('account_id'));
        $this->getJson('/api/admin/team', $headers)->assertOk()->assertJsonCount(2, 'data');

        // Without an account there's nothing to manage.
        $this->getJson('/api/admin/roles')->assertStatus(422);
    }
}
