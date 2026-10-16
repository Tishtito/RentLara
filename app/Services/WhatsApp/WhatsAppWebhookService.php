<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\WhatsAppConnection;
use App\Support\CurrentAccount;

/** Checks that webhook calls really come from the landlord's Meta app. */
class WhatsAppWebhookService
{
    public function __construct(private CurrentAccount $context) {}

    /** Meta's subscription handshake: our verify token must match the one the landlord pasted. */
    public function verifyChallenge(Account $account, ?string $mode, ?string $token): bool
    {
        $connection = $this->connection($account);

        return $connection && $mode === 'subscribe' && is_string($token)
            && hash_equals($connection->webhook_verify_token, $token);
    }

    /** X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(raw body, app secret). */
    public function verifySignature(Account $account, string $rawBody, ?string $header): bool
    {
        $connection = $this->connection($account);

        if (! $connection || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $connection->app_secret), substr($header, 7));
    }

    /**
     * Delivery statuses from a webhook payload (entry[].changes[].value.statuses[]).
     *
     * @return list<array<string, mixed>>
     */
    public function statuses(array $payload): array
    {
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }

    private function connection(Account $account): ?WhatsAppConnection
    {
        return $this->context->actingAs($account, fn () => WhatsAppConnection::query()->first());
    }
}
