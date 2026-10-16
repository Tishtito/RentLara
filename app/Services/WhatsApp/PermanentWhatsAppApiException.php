<?php

namespace App\Services\WhatsApp;

use App\Services\Notifications\PermanentDeliveryFailure;

/** A WhatsApp error that retrying won't fix; the delivery is marked failed and left alone. */
class PermanentWhatsAppApiException extends WhatsAppApiException implements PermanentDeliveryFailure
{
    public function retryable(): bool
    {
        return false;
    }
}
