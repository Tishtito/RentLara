<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\TenantInvitationRequested;
use App\Events\Commands\TenantUpdateSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTenantRequest;
use App\Http\Resources\AdminTenantResource;
use App\Models\Tenant;
use App\Services\Tenants\TenantDirectory;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Tenants & leases', weight: 5)]
class TenantController extends Controller
{
    public function __construct(private TenantDirectory $directory) {}

    /**
     * List tenants.
     *
     * Newest first, 20 per page, each with their current lease (unit, property, rent, balance).
     * Caretakers see tenants of the properties they look after. New tenants are added by moving them in.
     */
    #[QueryParameter('search', description: 'Name, phone, email, national ID or unit number. Several words must all match.', type: 'string', example: 'jane 7-7')]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tenant::class);

        return AdminTenantResource::collection($this->directory->list($request->user(), $request->string('search')->toString() ?: null));
    }

    /**
     * Show a tenant.
     *
     * Contact details, every lease (with balance), the latest 6 bills and 10 payments.
     */
    public function show(Tenant $tenant): AdminTenantResource
    {
        Gate::authorize('view', $tenant);

        return new AdminTenantResource($this->directory->detail($tenant));
    }

    /**
     * Edit a tenant's contact details.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('update', $tenant);

        TenantUpdateSubmitted::dispatch($tenant->id, $request->attributesToSave());

        return response()->json(['message' => 'Tenant updated.'], 202);
    }

    /**
     * Send the portal invitation again.
     *
     * Emails a new link (valid 3 days); earlier links stop working. Only for tenants with an email who
     * haven't set up their login yet.
     */
    public function invite(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('update', $tenant);

        TenantInvitationRequested::dispatch($tenant->id, $request->user()->id);

        return response()->json(['message' => 'Invitation sent.'], 202);
    }
}
