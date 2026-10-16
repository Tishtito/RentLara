<?php

namespace App\Http\Controllers\Platform;

use App\Events\Commands\FailedNotificationsRetryRequested;
use App\Events\Commands\NotificationRetryRequested;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformDeliveryResource;
use App\Services\Platform\PlatformMessaging;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Platform', weight: 20)]
class DeliveryController extends Controller
{
    /**
     * List messages (all accounts).
     *
     * Bill emails, WhatsApp messages and invitations, newest first, with the error for failed ones.
     */
    public function index(Request $request, PlatformMessaging $messaging): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            /** queued, sent, delivered or failed */
            'status' => ['nullable', 'in:queued,sent,delivered,failed'],
            /** email, sms or whatsapp */
            'channel' => ['nullable', 'in:email,sms,whatsapp'],
            'type' => ['nullable', 'string', 'max:50'],
            /** Recipient or error text. */
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return PlatformDeliveryResource::collection($messaging->deliveries($filters));
    }

    /**
     * Message statistics.
     *
     * Counts per channel and status for the last 24 hours and 7 days.
     */
    public function stats(PlatformMessaging $messaging): JsonResponse
    {
        return response()->json(['data' => $messaging->stats()]);
    }

    /**
     * Resend a failed message.
     *
     * Sends it again in the background.
     */
    public function retry(int $delivery, PlatformMessaging $messaging): JsonResponse
    {
        [$accountId, $deliveryId] = $messaging->retryTarget($delivery);

        NotificationRetryRequested::dispatch($accountId, $deliveryId);

        return response()->json(['message' => 'Resending.'], 202);
    }

    /**
     * Resend all failed messages.
     *
     * Every failed message that has an address, for one account (`account_id`) or all.
     */
    public function retryFailed(Request $request): JsonResponse
    {
        $request->validate(['account_id' => ['nullable', 'integer', 'exists:accounts,id']]);

        FailedNotificationsRetryRequested::dispatch($request->integer('account_id') ?: null);

        return response()->json(['message' => 'Resending failed messages.'], 202);
    }

    /**
     * List WhatsApp numbers.
     *
     * Each landlord's WhatsApp sender: status, number, template and messages sent / failed in the last
     * 7 days. Never includes tokens or secrets.
     */
    public function whatsapp(PlatformMessaging $messaging): JsonResponse
    {
        return response()->json(['data' => $messaging->whatsappConnections()]);
    }
}
