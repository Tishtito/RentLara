<?php

namespace Tests\Feature;

use App\Data\ChangeChargeRateData;
use App\Enums\BillingType;
use App\Events\ChargeRateChanged;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Rates\ChargeRateService;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChargeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private ChargeRateService $rates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Unit::factory()->create();
        $this->property = Property::withoutGlobalScopes()->findOrFail($this->unit->property_id);
        $this->rates = app(ChargeRateService::class);
        app(CurrentAccount::class)->set($this->unit->account_id);
    }

    public function test_changing_a_price_closes_the_old_version_instead_of_editing_it(): void
    {
        Event::fake([ChargeRateChanged::class]);

        $old = $this->rates->change($this->electricity('30.00', '2026-01-01'));
        $new = $this->rates->change($this->electricity('32.00', '2026-11-01'));

        $old->refresh();
        $this->assertSame('30.00', $old->rate);
        $this->assertSame('2026-10-31', $old->effective_to->toDateString());
        $this->assertSame(BillingType::Metered, $new->billing_type);  // carried over from the old version
        $this->assertSame('30.00', $this->rates->resolve($this->unit, 'electricity', CarbonImmutable::parse('2026-10-15'))->rate);
        $this->assertSame('32.00', $this->rates->resolve($this->unit, 'electricity', CarbonImmutable::parse('2026-11-15'))->rate);

        Event::assertDispatched(ChargeRateChanged::class, fn ($e) => $e->rate->is($new) && $e->previous->is($old));
    }

    public function test_a_unit_override_wins_over_the_property_rate(): void
    {
        $this->rates->change($this->electricity('30.00', '2026-01-01'));
        $this->rates->change(new ChangeChargeRateData(
            propertyId: $this->property->id, chargeCode: 'electricity', rate: '25.00',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'), unitId: $this->unit->id, billingType: BillingType::Metered,
        ));

        $this->assertSame('25.00', $this->rates->resolve($this->unit, 'electricity', CarbonImmutable::parse('2026-06-01'))->rate);
        $this->assertCount(1, $this->rates->allFor($this->unit, CarbonImmutable::parse('2026-06-01')));
    }

    public function test_a_new_version_must_start_after_the_current_one(): void
    {
        $this->rates->change($this->electricity('30.00', '2026-06-01'));

        // A validation error (422 on the API), not a server error.
        $this->expectException(ValidationException::class);
        $this->rates->change($this->electricity('32.00', '2026-05-01'));
    }

    private function electricity(string $rate, string $from): ChangeChargeRateData
    {
        return new ChangeChargeRateData(
            propertyId: $this->property->id, chargeCode: 'electricity', rate: $rate,
            effectiveFrom: CarbonImmutable::parse($from), label: 'Electricity', billingType: BillingType::Metered,
        );
    }
}
