<?php

namespace App\Http\Resources;

use App\Enums\SystemRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A role: built-in template or one of the account's own. @mixin \Spatie\Permission\Models\Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $template = SystemRole::tryFrom($this->name);
        $isTemplate = $this->getAttribute(config('permission.column_names.team_foreign_key')) === null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Built-in (shared by every account; edited by super admins only). */
            'is_template' => $isTemplate,
            /** Owner: always every permission, can't be changed. */
            'is_locked' => $isTemplate && (bool) $template?->isLocked(),
            'description' => $isTemplate ? $template?->description() : null,
            'permissions' => $this->permissions->pluck('name')->values(),
            /** How many people have this role in this account. */
            'users_count' => $this->whenHas('users_count'),
        ];
    }
}
