<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\LandlordRegistered;
use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Landlord self sign-up from the admin portal. */
class RegisterLandlordTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/signup'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_landlord_signs_up_and_then_logs_in(): void
    {
        Event::fake([LandlordRegistered::class]);

        $this->register(['business_name' => 'Mwangi Homes'])->assertAccepted();

        $account = Account::where('name', 'Mwangi Homes')->firstOrFail();
        $user = User::where('email', 'peter@example.com')->firstOrFail();

        $this->assertSame('mwangi-homes', $account->slug);
        $this->assertSame('active', $account->status->value ?? $account->status);
        $this->assertSame($account->id, $user->account_id);
        $this->assertSame(UserRole::Staff, $user->role);
        // The person who signs up owns the new account.
        $this->assertSame(['Owner'], app(CurrentAccount::class)->actingAs($account, fn () => $user->getRoleNames()->all()));
        $this->assertSame('+254712345678', $user->phone);
        Event::assertDispatched(LandlordRegistered::class, fn ($e) => $e->account->is($account) && $e->user->is($user));

        // Sign-up doesn't log in; the page logs in right after with the same credentials.
        $this->assertGuest('web');
        $this->postJson('/api/admin/auth/login', ['email' => 'peter@example.com', 'password' => 'secret-pass-1'], self::SPA)
            ->assertOk()
            ->assertJsonPath('user.account_id', $account->id);

        // The new landlord sees only their own (empty) account, not Kims Properties' payments.
        $this->getJson('/api/admin/payments', self::SPA)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_business_name_defaults_to_the_person_and_slugs_stay_unique(): void
    {
        $this->register(['email' => 'a@example.com', 'name' => 'Kims Properties'])->assertAccepted();

        $this->assertSame(['kims-properties', 'kims-properties-2'], Account::orderBy('id')->pluck('slug')->all());
    }

    public function test_validation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // several bad attempts in a row, not a rate-limit test

        $this->register(['email' => 'landlord@rentflow.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->register(['phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->register(['password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->register(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'peter@example.com']);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/admin/auth/register', [
            'name' => 'Peter Mwangi',
            'phone' => '0712 345 678',
            'email' => 'Peter@Example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            ...$overrides,
        ], self::SPA);
    }
}
