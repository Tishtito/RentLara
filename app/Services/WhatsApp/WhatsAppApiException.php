<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** An error answer from the WhatsApp Cloud API, with Meta's error code. */
class WhatsAppApiException extends RuntimeException
{
    /**
     * Temporary conditions: Meta asks to retry later (rate limits, service unavailable).
     * Everything else (not on WhatsApp, template missing, invalid token…) won't fix itself.
     *
     * @see https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
     */
    private const RETRYABLE_CODES = [1, 2, 4, 80007, 130429, 131000, 131016, 131048, 131056, 133004];

    public function __construct(
        string $message,
        public readonly ?int $metaCode = null,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $detail = $error['error_data']['details'] ?? null;
        $message = trim(($error['message'] ?? 'WhatsApp API error').($detail ? " ({$detail})" : ''));
        $code = isset($error['code']) ? (int) $error['code'] : null;

        $exception = new self("WhatsApp error {$code}: {$message}", $code, $response->status());

        return $exception->retryable() ? $exception : new PermanentWhatsAppApiException($exception->getMessage(), $code, $response->status());
    }

    public function retryable(): bool
    {
        return $this->httpStatus >= 500 || in_array($this->metaCode, self::RETRYABLE_CODES, true);
    }
}
