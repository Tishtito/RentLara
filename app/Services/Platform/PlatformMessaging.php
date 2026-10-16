<?php

namespace App\Services\Platform;

use App\Events\Commands\NotificationRetryRequested;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Bill emails / WhatsApp messages across every landlord account: what failed, resend, sender numbers. */
class PlatformMessaging
{
    public function __construct(
        private CurrentAccount $context,
        private NotificationDeliveryService $deliveries,
    ) {}

    /** @param  array{account_id?: int|null, status?: string|null, channel?: string|null, type?: string|null, q?: string|null}  $filters */
    public function deliveries(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        return $this->context->withoutScope(fn () => NotificationDelivery::query()
            ->with(['tenant:id,first_name,last_name', 'account:id,name'])
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('recipient', 'like', "%{$s}%")->orWhere('error', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate($perPage));
    }

    /** Counts per channel and status for the last 24 hours and 7 days. */
    public function stats(): array
    {
        $window = fn ($since) => DB::table('notification_deliveries')->where('created_at', '>=', $since)
            ->groupBy('channel', 'status')->selectRaw('channel, status, COUNT(*) as n')->get()
            ->map(fn ($r) => ['channel' => $r->channel, 'status' => $r->status, 'count' => (int) $r->n])->values();

        return [
            'last_24h' => $window(now()->subDay()),
            'last_7d' => $window(now()->subDays(7)),
            'failed_total' => DB::table('notification_deliveries')->where('status', 'failed')->count(),
        ];
    }

    /** Ask for a resend of every retryable failed delivery (one account, or all). @return int how many */
    public function retryFailed(?int $accountId = null): int
    {
        $failed = $this->context->withoutScope(fn () => $this->deliveries->retryable()
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->get(['id', 'account_id']));

        foreach ($failed as $delivery) {
            NotificationRetryRequested::dispatch($delivery->account_id, $delivery->id);
        }

        return $failed->count();
    }

    /** @return array{0: int, 1: int} [accountId, deliveryId] of a failed delivery, or 404 / 422 */
    public function retryTarget(int $deliveryId): array
    {
        $delivery = $this->context->withoutScope(fn () => NotificationDelivery::query()->findOrFail($deliveryId));

        abort_unless($delivery->status === 'failed', 422, 'Only failed messages can be resent.');

        return [$delivery->account_id, $delivery->id];
    }

    /** Each landlord's WhatsApp sender number. Never the token or app secret. */
    public function whatsappConnections()
    {
        return DB::table('whatsapp_connections')
            ->join('accounts', 'accounts.id', '=', 'whatsapp_connections.account_id')
            ->orderBy('accounts.name')
            ->get([
                'whatsapp_connections.account_id', 'accounts.name as account_name', 'whatsapp_connections.status',
                'whatsapp_connections.display_phone_number', 'whatsapp_connections.verified_name',
                'whatsapp_connections.template_name', 'whatsapp_connections.verified_at', 'whatsapp_connections.updated_at',
            ])
            ->map(function ($c) {
                $since = now()->subDays(7);
                $c->sent_7d = DB::table('notification_deliveries')->where('account_id', $c->account_id)->where('channel', 'whatsapp')->where('created_at', '>=', $since)->whereIn('status', ['sent', 'delivered'])->count();
                $c->failed_7d = DB::table('notification_deliveries')->where('account_id', $c->account_id)->where('channel', 'whatsapp')->where('created_at', '>=', $since)->where('status', 'failed')->count();

                return $c;
            });
    }
}
