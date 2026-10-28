<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One message (bill email, WhatsApp…) on the platform. @mixin \App\Models\NotificationDelivery */
class PlatformDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account' => $this->account ? ['id' => $this->account->id, 'name' => $this->account->name] : null,
            'tenant' => $this->tenant ? trim($this->tenant->first_name.' '.$this->tenant->last_name) : null,
            /** bill_issued, bill_resent, tenant_invitation… */
            'type' => $this->type,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            /** queued, sent, delivered or failed */
            'status' => $this->status,
            'provider' => $this->provider,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
