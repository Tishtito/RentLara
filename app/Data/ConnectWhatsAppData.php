<?php

namespace App\Data;

/**
 * A landlord's WhatsApp Cloud API credentials. Null token / secret on an update
 * means "keep the stored one".
 */
final readonly class ConnectWhatsAppData
{
    public function __construct(
        public int $accountId,
        public string $phoneNumberId,
        public string $businessAccountId,
        public ?string $accessToken = null,
        public ?string $appSecret = null,
        public ?string $templateName = null,
        public ?string $templateLanguage = null,
        public ?string $invitationTemplateName = null,
        public ?string $receiptTemplateName = null,
    ) {}
}
