<?php

namespace App\Http\Resources;

use App\Models\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes the access token or app secret.
 *
 * @mixin WhatsAppConnection
 */
class WhatsAppConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            /** @example +254 700 000001 */
            'display_phone_number' => $this->display_phone_number,
            'verified_name' => $this->verified_name,
            'template_name' => $this->template_name,
            'template_language' => $this->template_language,
            'verified_at' => $this->verified_at,
            /** Paste into Meta: WhatsApp → Configuration → Webhook → Callback URL. */
            'webhook_url' => route('webhooks.whatsapp', $this->account->slug),
            /** Paste into Meta as the webhook's Verify token. */
            'webhook_verify_token' => $this->webhook_verify_token,
        ];
    }
}
