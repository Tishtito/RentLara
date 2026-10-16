<?php

namespace App\Http\Controllers;

use App\Events\Commands\WhatsAppStatusesReceived;
use App\Models\Account;
use App\Services\WhatsApp\WhatsAppWebhookService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Called by Meta, not by people: one webhook URL per landlord (each has their own Meta app). */
#[Group('Webhooks', weight: 9)]
class WhatsAppWebhookController extends Controller
{
    public function __construct(private WhatsAppWebhookService $webhooks) {}

    /**
     * WhatsApp webhook verification.
     *
     * Meta calls this once when the landlord saves the webhook in their app; it must echo
     * `hub.challenge` when `hub.verify_token` matches the landlord's verify token.
     *
     * @unauthenticated
     */
    public function verify(Request $request, Account $account): Response
    {
        // PHP turns the "hub.mode" query keys into "hub_mode".
        abort_unless($this->webhooks->verifyChallenge($account, $request->query('hub_mode'), $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * WhatsApp delivery statuses.
     *
     * Meta posts message statuses here (sent, delivered, read, failed). Requests must be signed
     * with the landlord's app secret (`X-Hub-Signature-256`), otherwise 403.
     *
     * @unauthenticated
     */
    public function store(Request $request, Account $account): JsonResponse
    {
        abort_unless($this->webhooks->verifySignature($account, $request->getContent(), $request->header('X-Hub-Signature-256')), 403);

        $statuses = $this->webhooks->statuses($request->json()->all());

        if ($statuses !== []) {
            WhatsAppStatusesReceived::dispatch($account->id, $statuses);
        }

        return response()->json(['received' => true]);
    }
}
