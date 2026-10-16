<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** A billing run on the platform: which account, and who started it. @mixin \App\Models\BillingRun */
class PlatformBillingRunResource extends BillingRunResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'account' => $this->account ? ['id' => $this->account->id, 'name' => $this->account->name] : null,
            'triggered_by' => $this->triggeredBy?->name,
        ];
    }
}
