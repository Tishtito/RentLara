<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Events\InvoiceVoided;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voiding a wrong bill. The bill stays (marked void, with who / when / why) but no longer counts towards
 * arrears; a monthly bill sends its lease back one month so the next billing run bills that month again with the
 * corrected readings or prices. Only the lease's latest bill, and only before any payment is applied,
 * so no later bill's brought-forward balance ever changes.
 */
class InvoiceVoidService
{
    /** @throws ValidationException already void, paid (partly), or not the lease's latest bill */
    public function void(int $invoiceId, string $reason, ?int $voidedBy): Invoice
    {
        $invoice = DB::transaction(function () use ($invoiceId, $reason, $voidedBy) {
            $invoice = Invoice::query()->with('lease')->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->isVoid()) {
                throw ValidationException::withMessages(['invoice' => 'This bill is already void.']);
            }

            if ($invoice->payments()->where('status', PaymentStatus::Confirmed)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'A payment is applied to this bill. Move or reverse the payment first.']);
            }

            $later = Invoice::query()->where('lease_id', $invoice->lease_id)->where('status', '!=', InvoiceStatus::Void)
                ->where('period_start', '>', $invoice->period_start)->exists();
            if ($later) {
                throw ValidationException::withMessages(['invoice' => 'Only the latest bill of a lease can be voided. Void the newer bill first.']);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'billing_key' => null,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
                'void_reason' => trim($reason),
            ])->save();

            if ($invoice->type === InvoiceType::MoveIn) {
                // The deposit can be charged again; the monthly schedule stays as it is. (A corrected
                // move-in bill isn't re-made automatically yet.)
                $invoice->lease->forceFill(['deposit_invoiced_at' => null])->save();
            } else {
                // Back one month: the bill's billing day (its period start) is due again, and rent counts as
                // billed only up to the day before it — or before its billing-day gap, if it charged one.
                $gapDays = (int) $invoice->items()->where('charge_code', InvoiceBuilder::RENT_ADJUSTMENT)->value('quantity');
                $invoice->lease->forceFill([
                    'next_billing_date' => Carbon::parse($invoice->period_start)->toDateString(),
                    'last_billed_on' => Carbon::parse($invoice->period_start)->subDays($gapDays + 1)->toDateString(),
                ])->save();
            }

            return $invoice;
        });

        InvoiceVoided::dispatch($invoice);

        return $invoice;
    }
}
