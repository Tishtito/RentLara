<?php

use App\Jobs\GenerateDueInvoices;
use Illuminate\Support\Facades\Schedule;

// Bill every landlord's due leases each morning (Nairobi time): one queued billing run per account.
// `php artisan billing:run` does the same by hand.
Schedule::job(new GenerateDueInvoices)->dailyAt('06:00')->timezone('Africa/Nairobi');
