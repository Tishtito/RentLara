<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Models\ChargeRate;
use App\Models\Property;
use App\Models\Unit;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AccountScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_return_nothing_without_an_account_context(): void
    {
        Unit::factory()->count(2)->create();

        $this->assertSame(0, Unit::count());
        $this->assertSame(2, app(CurrentAccount::class)->withoutScope(fn () => Unit::count()));
    }

    public function test_each_landlord_only_sees_their_own_rows(): void
    {
        $mine = Unit::factory()->create();
        Unit::factory()->create();

        $ids = app(CurrentAccount::class)->actingAs($mine->account_id, fn () => Unit::pluck('id')->all());

        $this->assertSame([$mine->id], $ids);
    }

    public function test_saving_a_row_for_another_account_throws(): void
    {
        $mine = Property::factory()->create();
        $theirs = Property::factory()->create();

        $this->expectException(LogicException::class);

        app(CurrentAccount::class)->actingAs($mine->account_id, fn () => Unit::create([
            'account_id' => $theirs->account_id,
            'property_id' => $theirs->id,
            'unit_number' => '1-1',
        ]));
    }

    public function test_charge_rates_cannot_be_edited_only_versioned(): void
    {
        $property = Property::factory()->create();

        app(CurrentAccount::class)->actingAs($property->account_id, function () use ($property) {
            $old = ChargeRate::create([
                'property_id' => $property->id, 'charge_code' => 'electricity', 'label' => 'Electricity',
                'billing_type' => BillingType::Metered, 'rate' => '30.00', 'effective_from' => '2026-01-01',
            ]);

            $new = ChargeRate::change($property, 'electricity', '32.00', '2026-11-01');

            $this->assertSame('2026-10-31', $old->fresh()->effective_to->toDateString());
            $this->assertSame('30.00', ChargeRate::resolve(Unit::factory()->for($property)->create(), 'electricity', '2026-10-15')->rate);
            $this->assertSame($new->id, ChargeRate::resolve(Unit::first(), 'electricity', '2026-11-15')->id);

            $this->expectException(LogicException::class);
            $old->fresh()->update(['rate' => '35.00']);
        });
    }
}
