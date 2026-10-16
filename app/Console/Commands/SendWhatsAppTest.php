<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppBillSender;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\CurrentAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:test {account : Account ID} {phone : Recipient, e.g. +254712345678} {--template=hello_world : Template to send} {--language=en_US : Template language code}')]
#[Description("Send a test WhatsApp template from a landlord's number to check their credentials")]
class SendWhatsAppTest extends Command
{
    public function handle(CurrentAccount $context, WhatsAppClient $client): int
    {
        $connection = $context->actingAs((int) $this->argument('account'), fn () => WhatsAppConnection::query()->first());

        if (! $connection) {
            $this->error('This account has no WhatsApp connection. Connect it with PUT /api/admin/whatsapp.');

            return self::FAILURE;
        }

        try {
            $id = $client->sendTemplate(
                $connection,
                WhatsAppBillSender::toWhatsAppNumber($this->argument('phone')),
                $this->option('template'),
                $this->option('language'),
            );
        } catch (WhatsAppApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent from {$connection->display_phone_number}. Message ID: {$id}");

        return self::SUCCESS;
    }
}
