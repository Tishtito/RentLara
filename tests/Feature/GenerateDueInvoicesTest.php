<?php

namespace Tests\Feature;

use App\Jobs\GenerateDueInvoices;
use App\Models\BillingRun;
use App\Models\Invoice;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** The daily 06:00 job that bills every lease whose billing day has come. */
class GenerateDueInvoicesTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims(); // today = 2026-11-05, Kims' billing day
    }

    public function test_it_bills_due_leases_of_active_accounts(): void
    {
        GenerateDueInvoices::dispatch();

        $bill = $this->firstInvoice();
        $this->assertSame(['monthly', '19500.00'], [$bill->type->value, $bill->total_due]);
        $this->assertSame('scheduled', $this->asKims(fn () => BillingRun::sole())->trigger);

        // Once a day is enough: running again bills nothing new.
        GenerateDueInvoices::dispatch();
        $this->assertSame(1, $this->asKims(fn () => Invoice::count()));
    }

    public function test_suspended_accounts_are_not_billed(): void
    {
        $this->kims->forceFill(['status' => 'suspended'])->save();

        GenerateDueInvoices::dispatch();

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_it_is_scheduled_daily_at_06_00_nairobi(): void
    {
        $events = collect(app(Schedule::class)->events());

        $job = $events->first(fn ($e) => $e instanceof CallbackEvent && str_contains((string) $e->description, GenerateDueInvoices::class));
        $this->assertNotNull($job, 'GenerateDueInvoices is not scheduled.');
        $this->assertSame('0 6 * * *', $job->expression);
        $this->assertSame('Africa/Nairobi', $job->timezone);

        $this->assertFalse($events->contains(fn ($e) => str_contains((string) $e->command, 'billing:run')), 'billing:run is still scheduled too.');
    }
}
