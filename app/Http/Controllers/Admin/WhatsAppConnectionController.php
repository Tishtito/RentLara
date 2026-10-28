<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\WhatsAppConnectionSubmitted;
use App\Events\Commands\WhatsAppDisconnectRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWhatsAppConnectionRequest;
use App\Http\Resources\WhatsAppConnectionResource;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppConnectionService;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('WhatsApp', weight: 4)]
class WhatsAppConnectionController extends Controller
{
    /**
     * Show the WhatsApp connection.
     *
     * The landlord's WhatsApp Business number used to send bills, and the webhook URL and verify
     * token to paste into the Meta app (WhatsApp → Configuration → Webhook, field `messages`).
     * 404 when WhatsApp isn't connected yet.
     */
    public function show(WhatsAppConnectionService $connections): WhatsAppConnectionResource
    {
        Gate::authorize('view', WhatsAppConnection::class);

        $connection = $connections->current() ?? abort(404, 'WhatsApp is not connected.');

        return new WhatsAppConnectionResource($connection);
    }

    /**
     * Connect a WhatsApp number.
     *
     * Saves the landlord's WhatsApp Cloud API credentials from their own Meta app. They're checked
     * with Meta first (422 if Meta rejects them). From then on every new bill is also sent on
     * WhatsApp, using the approved `rentflow_bill_issued` template, every tenant invitation with
     * `rentflow_tenant_invitation` and every payment receipt with `rentflow_payment_receipt`
     * (see docs/WHATSAPP.md). Send it again to update;
     * leave `access_token` / `app_secret` out to keep the stored ones.
     */
    public function update(StoreWhatsAppConnectionRequest $request): JsonResponse
    {
        Gate::authorize('update', WhatsAppConnection::class);

        WhatsAppConnectionSubmitted::dispatch($request->toData());

        return response()->json(['message' => 'WhatsApp connected.'], 202);
    }

    /**
     * Disconnect WhatsApp.
     *
     * Stops sending bills on WhatsApp; email continues. The credentials are kept so it can be
     * switched back on by connecting again.
     */
    public function destroy(CurrentAccount $context): JsonResponse
    {
        Gate::authorize('update', WhatsAppConnection::class);
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        WhatsAppDisconnectRequested::dispatch($context->id());

        return response()->json(['message' => 'WhatsApp disconnected.'], 202);
    }
}
