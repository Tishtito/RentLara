<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Payment;
use Carbon\CarbonInterface;

class ReceiptNumberGenerator
{
    public const PREFIX = 'RCT';

    /** Next receipt number for an account and year, e.g. RCT-2026-000042. Call inside the payment's transaction. */
    public function next(Account $account, CarbonInterface $paidAt): string
    {
        $prefix = self::PREFIX."-{$paidAt->year}-";

        $last = Payment::query()
            ->forAccount($account)
            ->where('receipt_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
