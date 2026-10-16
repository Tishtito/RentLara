<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The landlord account. @mixin \App\Models\Account */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            /** Day of the month every lease is billed (1–28). */
            'billing_day' => $this->billing_day,
            'payment_due_days' => $this->payment_due_days,
            'invoice_prefix' => $this->invoice_prefix,
            'status' => $this->status,
        ];
    }
}
