<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Someone in the landlord's team. @mixin \App\Models\User */
class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            /** active, invited (hasn't set a password yet) or suspended. */
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'roles' => $this->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            /** Properties they look after. */
            'properties' => $this->caretakerProperties->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            /** It's you (you can't change your own roles, suspend or remove yourself). */
            'is_me' => $request->user()?->id === $this->id,
        ];
    }
}
