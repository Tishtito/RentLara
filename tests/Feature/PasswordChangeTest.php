<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** "Change password" while logged in, in both portals. */
class PasswordChangeTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_admin_changes_their_password_and_other_apps_log_out(): void
    {
        $user = User::where('email', 'landlord@rentflow.test')->firstOrFail();
        $other = $user->createToken('Old phone', ['admin']);
        $current = $user->createToken('This phone', ['admin'])->plainTextToken;
        $auth = ['Authorization' => "Bearer {$current}"];

        $this->putJson('/api/admin/auth/password', ['current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/admin/auth/password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/admin/auth/password', ['current_password' => 'password', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'], $auth)
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertNull(PersonalAccessToken::find($other->accessToken->id));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/user', $auth)->assertOk(); // this device stays logged in
        $this->assertTrue(AuditLog::where('action', 'auth.password_changed')->exists());
    }

    public function test_tenant_changes_their_password(): void
    {
        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
        $other = $jane->createToken('Old phone', ['portal']);
        $current = $jane->createToken('This phone', ['portal'])->plainTextToken;
        $auth = ['Authorization' => "Bearer {$current}"];

        $this->putJson('/api/portal/auth/password', ['current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/portal/auth/password', ['current_password' => 'password', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'], $auth)
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $jane->fresh()->password));
        $this->assertNull(PersonalAccessToken::find($other->accessToken->id));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/portal/user', $auth)->assertOk();
    }

    public function test_each_portal_only_changes_its_own_login(): void
    {
        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
        $tenantToken = $jane->createToken('phone', ['portal'])->plainTextToken;
        $adminToken = User::where('email', 'landlord@rentflow.test')->firstOrFail()->createToken('phone', ['admin'])->plainTextToken;
        $body = ['current_password' => 'password', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

        $this->putJson('/api/admin/auth/password', $body, ['Authorization' => "Bearer {$tenantToken}"])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/portal/auth/password', $body, ['Authorization' => "Bearer {$adminToken}"])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/portal/auth/password', $body)->assertUnauthorized();
    }
}
