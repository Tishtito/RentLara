<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Invoice;
use App\Support\CurrentAccount;
use Closure;
use Database\Seeders\KimsPropertiesSeeder;
use Illuminate\Support\Carbon;

/**
 * Seeds Kims Properties (House 7-7, first monthly bill on the billing day 2026-11-05) and helpers to bill it.
 * Tests run with QUEUE_CONNECTION=sync, so billing:run bills immediately.
 */
trait BillsKimsProperties
{
    protected Account $kims;

    protected function seedKims(): void
    {
        Carbon::setTestNow('2026-11-05 06:00:00');
        $this->seed(KimsPropertiesSeeder::class);
        $this->kims = Account::where('slug', 'kims-properties')->firstOrFail();
    }

    protected function runBilling(string $date = '2026-11-05'): void
    {
        $this->artisan('billing:run', ['--date' => $date])->assertSuccessful();
    }

    protected function asKims(Closure $callback): mixed
    {
        return app(CurrentAccount::class)->actingAs($this->kims, $callback);
    }

    protected function firstInvoice(): Invoice
    {
        return $this->asKims(fn () => Invoice::with('items')->orderBy('id')->firstOrFail());
    }
}
