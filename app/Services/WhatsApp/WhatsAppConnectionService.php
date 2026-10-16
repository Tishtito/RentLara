<?php

namespace App\Services\WhatsApp;

use App\Data\ConnectWhatsAppData;
use App\Events\WhatsAppConnected;
use App\Events\WhatsAppDisconnected;
use App\Models\WhatsAppConnection;
use App\Support\CurrentAccount;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Connects a landlord's own WhatsApp Business number, after checking the credentials with Meta. */
class WhatsAppConnectionService
{
    public function __construct(
        private CurrentAccount $context,
        private WhatsAppClient $client,
    ) {}

    /** @throws ValidationException when Meta rejects the credentials */
    public function connect(ConnectWhatsAppData $data): WhatsAppConnection
    {
        $connection = $this->context->actingAs($data->accountId, function () use ($data) {
            $connection = WhatsAppConnection::query()->firstOrNew();

            $connection->fill(array_filter([
                'account_id' => $data->accountId,
                'phone_number_id' => $data->phoneNumberId,
                'business_account_id' => $data->businessAccountId,
                'access_token' => $data->accessToken,
                'app_secret' => $data->appSecret,
                'template_name' => $data->templateName ?? $connection->template_name ?? config('whatsapp.bill_template.name'),
                'template_language' => $data->templateLanguage ?? $connection->template_language ?? config('whatsapp.bill_template.language'),
            ], fn ($value) => $value !== null));

            try {
                $number = $this->client->phoneNumber($connection);
            } catch (WhatsAppApiException $e) {
                throw ValidationException::withMessages([
                    'access_token' => "Meta did not accept these credentials: {$e->getMessage()}",
                ]);
            }

            $connection->forceFill([
                'display_phone_number' => $number['display_phone_number'],
                'verified_name' => $number['verified_name'],
                'webhook_verify_token' => $connection->webhook_verify_token ?: Str::random(40),
                'status' => 'active',
                'verified_at' => now(),
            ])->save();

            return $connection;
        });

        WhatsAppConnected::dispatch($connection);

        return $connection;
    }

    public function disconnect(int $accountId): void
    {
        $connection = $this->context->actingAs($accountId, function () {
            $connection = WhatsAppConnection::query()->first();
            $connection?->forceFill(['status' => 'disabled'])->save();

            return $connection;
        });

        if ($connection) {
            WhatsAppDisconnected::dispatch($connection);
        }
    }

    /** The current account's connection (active or not), for the admin screen. */
    public function current(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->with('account')->first();
    }

    public function isActive(): bool
    {
        return WhatsAppConnection::query()->where('status', 'active')->exists();
    }
}
