<?php

namespace App\Http\Resources;

use App\Models\BillingRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BillingRun */
class BillingRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_date' => $this->run_date?->toDateString(),
            'trigger' => $this->trigger,
            'status' => $this->status,
            'invoices_created' => $this->invoices_created,
            'leases_skipped' => $this->leases_skipped,
            'errors' => $this->errors ?? [],
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
