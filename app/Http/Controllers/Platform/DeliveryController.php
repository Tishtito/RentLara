<?php

namespace App\Http\Controllers\Platform;

use App\Events\Commands\FailedNotificationsRetryRequested;
use App\Events\Commands\NotificationRetryRequested;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformDeliveryResource;
use App\Services\Notifications\MessagePlan;
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
     * Sends it again in the background. `messages` says which channel and address it goes to.
     */
    public function retry(int $delivery, PlatformMessaging $messaging): JsonResponse
    {
        $target = $messaging->retryTarget($delivery);

        NotificationRetryRequested::dispatch($target->account_id, $target->id);

        return response()->json([
            'message' => 'Resending.',
            'messages' => [MessagePlan::sending($target->channel, $target->recipient)],
        ], 202);
    }

    /**
     * Resend all failed messages.
     *
     * Every failed message that has an address, for one account (`account_id`) or all. `messages`
     * gives how many go out on each channel (`count`).
     */
    public function retryFailed(Request $request, PlatformMessaging $messaging): JsonResponse
    {
        $request->validate(['account_id' => ['nullable', 'integer', 'exists:accounts,id']]);
        $accountId = $request->integer('account_id') ?: null;
        $counts = $messaging->retryableCounts($accountId);

        FailedNotificationsRetryRequested::dispatch($accountId);

        return response()->json(['message' => 'Resending failed messages.', 'messages' => $counts], 202);
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
