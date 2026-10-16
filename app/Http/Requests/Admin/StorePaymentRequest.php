<?php

namespace App\Http\Requests\Admin;

use App\Data\RecordPaymentData;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Rules\InCurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    private ?Lease $lease = null;

    /** Authorization needs the validated lease, so the controller checks the policy after validation. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * The lease being paid for.
             *
             * @example 1
             */
            'lease_id' => ['required', 'integer', new InCurrentAccount(Lease::class)],
            /** The bill to apply the payment to. Defaults to the lease's latest bill. */
            'invoice_id' => ['nullable', 'integer', new InCurrentAccount(Invoice::class)],
            /**
             * Amount in KES.
             *
             * @example 5000
             */
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            /**
             * M-Pesa confirmation code, bank reference or receipt number.
             *
             * @example SJK1A2B3C4
             */
            'reference' => ['nullable', 'string', 'max:60', 'required_if:method,mpesa,bank'],
            /** @example +254712345678 */
            'payer_phone' => ['nullable', 'string', 'max:20'],
            /**
             * When the money was received.
             *
             * @example 2026-10-01 10:30:00
             */
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $lease = $this->lease();

            if ($this->filled('invoice_id')
                && ! Invoice::query()->whereKey($this->integer('invoice_id'))->where('lease_id', $lease->id)->exists()) {
                $validator->errors()->add('invoice_id', 'The invoice does not belong to this lease.');
            }

            // Same M-Pesa code / bank reference twice is a duplicate callback or a typo.
            if ($this->filled('reference') && Payment::withoutGlobalScopes()
                ->where('account_id', $lease->account_id)
                ->where('method', $this->input('method'))
                ->where('reference', $this->input('reference'))
                ->exists()) {
                $validator->errors()->add('reference', 'A payment with this reference is already recorded.');
            }
        }];
    }

    public function lease(): Lease
    {
        return $this->lease ??= Lease::query()->findOrFail($this->integer('lease_id'));
    }

    public function toData(): RecordPaymentData
    {
        return new RecordPaymentData(
            accountId: $this->lease()->account_id,
            leaseId: $this->lease()->id,
            amount: number_format((float) $this->input('amount'), 2, '.', ''),
            method: PaymentMethod::from($this->input('method')),
            paidAt: CarbonImmutable::parse($this->input('paid_at')),
            reference: $this->input('reference'),
            payerPhone: $this->input('payer_phone'),
            invoiceId: $this->filled('invoice_id') ? $this->integer('invoice_id') : null,
            recordedBy: $this->user()->id,
            notes: $this->input('notes'),
        );
    }
}
