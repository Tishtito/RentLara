<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentAccount;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The activity log. Every accepted intent event (App\Events\Commands\*) and every login/logout is
 * recorded with who did it, in which account and what they sent (secrets redacted).
 */
class AuditLogService
{
    /** Intent events not worth logging (machine traffic). */
    private const SKIP = ['WhatsAppStatusesReceived'];

    /** Keys whose values never reach the log (matched case-insensitively, camelCase or snake_case). */
    private const SECRET = '/password|token|secret/i';

    /** Readable sentence per action; anything missing gets a generic one. */
    private const SUMMARIES = [
        'account_billing_settings.submitted' => 'Changed billing settings',
        'admin_password_reset.requested' => 'Asked for a password reset link',
        'billing_run.requested' => 'Ran billing',
        'charge_rate_change.submitted' => 'Changed a price',
        'landlord_registration.submitted' => 'Signed up a landlord account',
        'lease_end.requested' => 'Moved a tenant out',
        'invoice_resend.requested' => 'Resent a bill',
        'invoice_void.requested' => 'Voided a bill',
        'meter_readings.submitted' => 'Recorded meter readings',
        'meter.submitted' => 'Saved a meter',
        'meters.submitted' => 'Fitted meters',
        'meter_reading_correction.submitted' => 'Corrected a meter reading',
        'move_in.submitted' => 'Moved a tenant in',
        'notification_retry.requested' => 'Resent a message',
        'failed_notifications_retry.requested' => 'Resent all failed messages',
        'payment.submitted' => 'Recorded a payment',
        'property.submitted' => 'Saved a property',
        'role_delete.requested' => 'Deleted a role',
        'role.submitted' => 'Saved a role',
        'role_template_update.submitted' => 'Changed a built-in role',
        'staff_status.requested' => 'Changed a team member\'s access',
        'staff.submitted' => 'Saved a team member',
        'tenant_invitation.requested' => 'Sent a tenant invitation',
        'tenant_update.submitted' => 'Edited a tenant',
        'unit.submitted' => 'Saved a unit',
        'whats_app_connection.submitted' => 'Connected WhatsApp',
        'whats_app_disconnect.requested' => 'Disconnected WhatsApp',
        'account_creation.submitted' => 'Created a landlord account',
        'account_profile.submitted' => 'Edited a landlord account',
        'account_status.requested' => 'Changed a landlord account\'s status',
        'platform_admin.submitted' => 'Invited a platform admin',
        'platform_admin_status.requested' => 'Changed a platform admin\'s access',
        'auth.login' => 'Logged in',
        'auth.logout' => 'Logged out',
        'auth.token' => 'Logged in on the app',
    ];

    /** Payload keys that identify what the write was about, in order of preference. */
    private const SUBJECTS = [
        'invoiceId' => 'invoice', 'leaseId' => 'lease', 'unitId' => 'unit', 'propertyId' => 'property', 'tenantId' => 'tenant',
        'userId' => 'user', 'roleId' => 'role', 'deliveryId' => 'notification_delivery', 'meterId' => 'meter',
    ];

    public function __construct(private CurrentAccount $context) {}

    /** Record an accepted intent event (called after its own listener succeeded). */
    public function recordIntent(object $event): void
    {
        $short = class_basename($event);
        if (in_array($short, self::SKIP, true)) {
            return;
        }

        $action = $this->action($short);
        $data = $this->redact($this->flatten(get_object_vars($event)));
        $accountId = $this->context->id() ?? $this->find($data, 'accountId');
        [$subjectType, $subjectId] = $this->subject($data);

        $this->write($action, $accountId, $data, $subjectType, $subjectId, $this->actor());
    }

    /** Logins and logouts (both guards). */
    public function recordAuth(string $action, ?Authenticatable $who): void
    {
        if (! $who instanceof Model) {
            return;
        }

        $this->write($action, $who->getAttribute('account_id'), null, null, null, $who);
    }

    /**
     * @param  array{account_id?: int|null, actor?: string|null, action?: string|null, from?: string|null, to?: string|null, q?: string|null}  $filters
     */
    public function search(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('account:id,name')
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['actor'] ?? null, fn ($q, $a) => $q->where('actor_name', 'like', "%{$a}%"))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('summary', 'like', "%{$s}%")->orWhere('actor_name', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate($perPage);
    }

    /** @return list<array{action: string, label: string}> actions that appear in the log, for filters */
    public function actions(): array
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')
            ->map(fn (string $a) => ['action' => $a, 'label' => self::SUMMARIES[$a] ?? $a])->all();
    }

    /** @return Collection<int, AuditLog> */
    public function latest(?int $accountId = null, int $limit = 10)
    {
        return AuditLog::query()->with('account:id,name')
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->latest('id')->limit($limit)->get();
    }

    private function write(string $action, ?int $accountId, ?array $data, ?string $subjectType, ?int $subjectId, ?Model $actor): void
    {
        // Only an HTTP request has a route; scheduler, queue and artisan writes are "system".
        $request = request()->route() ? request() : null;

        AuditLog::query()->create([
            'account_id' => $accountId,
            'actor_type' => match (true) {
                $actor instanceof User => 'user',
                $actor instanceof Tenant => 'tenant',
                $request !== null => 'guest',
                default => 'system',
            },
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor ? ($actor->getAttribute('name') ?? $actor->getAttribute('full_name')) : null,
            'via_platform' => $actor instanceof User && $actor->isSuperAdmin(),
            'action' => $action,
            'summary' => self::SUMMARIES[$action] ?? Str::ucfirst(str_replace(['_', '.'], ' ', $action)),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'data' => $data ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    private function actor(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    /** PaymentSubmitted → payment.submitted; WhatsAppConnectionSubmitted → whats_app_connection.submitted */
    private function action(string $class): string
    {
        if (preg_match('/^(.*)(Submitted|Requested)$/', $class, $m)) {
            return Str::snake($m[1]).'.'.strtolower($m[2]);
        }

        return Str::snake($class);
    }

    /** Public properties as plain arrays: DTOs become arrays, models become their key, dates strings. */
    private function flatten(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Model => ['id' => $value->getKey(), 'type' => class_basename($value)],
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toIso8601String(),
            is_object($value) => $this->flatten(get_object_vars($value)),
            is_array($value) => array_map(fn ($v) => $this->flatten($v), $value),
            default => $value,
        };
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET, $key) && $value !== null) {
            return '[redacted]';
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->redact($v, is_string($k) ? $k : null);
            }
        }

        return $value;
    }

    /** First value of $key anywhere in the payload (top level first). */
    private function find(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data) && is_scalar($data[$key])) {
            return $data[$key];
        }

        foreach ($data as $v) {
            if (is_array($v) && ($found = $this->find($v, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?int} */
    private function subject(array $data): array
    {
        foreach (self::SUBJECTS as $key => $type) {
            $id = $this->find($data, $key);
            if (is_numeric($id)) {
                return [$type, (int) $id];
            }
        }

        return [null, null];
    }
}
