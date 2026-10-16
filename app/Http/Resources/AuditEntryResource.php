<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One activity-log entry. @mixin \App\Models\AuditLog */
class AuditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at,
            /** e.g. payment.submitted, auth.login */
            'action' => $this->action,
            'summary' => $this->summary,
            'account' => $this->account ? ['id' => $this->account->id, 'name' => $this->account->name] : null,
            /** user, tenant, system (scheduler / commands) or guest (not logged in). */
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            /** A platform admin did it. */
            'via_platform' => $this->via_platform,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            /** What was sent, with passwords / tokens / secrets replaced by "[redacted]". */
            'data' => $this->data,
            'ip' => $this->ip,
        ];
    }
}
