<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Invoice;
use Carbon\CarbonInterface;

class InvoiceNumberGenerator
{
    /** Next number for an account and year, e.g. INV-2026-000124. Call inside the billing transaction. */
    public function next(Account $account, CarbonInterface $issueDate): string
    {
        $prefix = "{$account->invoice_prefix}-{$issueDate->year}-";

        $last = Invoice::withTrashed()
            ->forAccount($account)
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
