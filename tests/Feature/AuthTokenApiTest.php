<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

class AuthTokenApiTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_token_works_for_requests_from_the_docs_page(): void
    {
        $token = $this->issueToken('landlord@rentflow.test')
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.roles', ['Owner'])
            ->json('token');

        // The docs page lives on the API's own origin. With Sanctum's default stateful domains this
        // write would go through CSRF and fail with 419; bearer requests must not.
        $headers = ['Authorization' => "Bearer {$token}", 'Origin' => 'http://localhost:8000', 'Referer' => 'http://localhost:8000/docs'];

        $this->getJson('/api/admin/user', $headers)->assertOk()->assertJsonPath('user.email', 'landlord@rentflow.test');
        $this->postJson('/api/admin/billing-runs', [], $headers)->assertAccepted();

        $this->assertNotNull(User::where('email', 'landlord@rentflow.test')->value('last_login_at'));
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $this->issueToken('landlord@rentflow.test', 'wrong')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->issueToken('nobody@rentflow.test')->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_suspended_login_or_account_is_refused(): void
    {
        User::where('email', 'caretaker@rentflow.test')->update(['status' => 'suspended']);
        $this->issueToken('caretaker@rentflow.test')->assertForbidden();

        Account::whereKey($this->kims->id)->update(['status' => 'suspended']);
        $this->issueToken('landlord@rentflow.test')->assertForbidden();
    }

    public function test_revoking_the_token_logs_it_out(): void
    {
        $token = $this->issueToken('landlord@rentflow.test')->json('token');

        $this->deleteJson('/api/admin/auth/token', [], ['Authorization' => "Bearer {$token}"])->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/user', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    private function issueToken(string $email, string $password = 'password')
    {
        return $this->postJson('/api/admin/auth/token', ['email' => $email, 'password' => $password, 'device_name' => 'tests']);
    }
}
