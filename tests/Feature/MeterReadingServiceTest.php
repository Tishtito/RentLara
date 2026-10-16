<?php

namespace Tests\Feature;

use App\Events\MeterReadingRecorded;
use App\Models\Meter;
use App\Models\Unit;
use App\Services\Meters\MeterReadingService;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MeterReadingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Meter $meter;

    private MeterReadingService $readings;

    protected function setUp(): void
    {
        parent::setUp();
        $unit = Unit::factory()->create();
        app(CurrentAccount::class)->set($unit->account_id);
        $this->meter = Meter::create(['unit_id' => $unit->id, 'charge_code' => 'water', 'initial_reading' => '70.00']);
        $this->readings = app(MeterReadingService::class);
    }

    public function test_records_a_reading_and_dispatches_an_event(): void
    {
        Event::fake([MeterReadingRecorded::class]);

        $reading = $this->readings->record($this->meter, CarbonImmutable::parse('2026-09-29'), '75.00');

        $this->assertSame('75.00', $reading->reading);
        Event::assertDispatched(MeterReadingRecorded::class);
    }

    public function test_a_reading_below_the_initial_reading_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->readings->record($this->meter, CarbonImmutable::parse('2026-09-29'), '69.00');
    }

    public function test_a_backdated_reading_cannot_exceed_a_later_one(): void
    {
        $this->readings->record($this->meter, CarbonImmutable::parse('2026-09-29'), '75.00');

        $this->expectException(ValidationException::class);
        $this->readings->record($this->meter, CarbonImmutable::parse('2026-09-15'), '76.00');
    }
}
