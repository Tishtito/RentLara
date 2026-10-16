<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\PropertySubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Services\Properties\PropertyService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Properties & units', weight: 4)]
class PropertyController extends Controller
{
    public function __construct(private PropertyService $properties) {}

    /**
     * List properties.
     *
     * With unit counts (`units_count`, `occupied_count`, `vacant_count`) and `arrears_total`, what the
     * tenants in each property owe. Caretakers see the properties they look after.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Property::class);

        return PropertyResource::collection($this->properties->list($request->user()));
    }

    /**
     * Show a property.
     */
    public function show(Request $request, Property $property): PropertyResource
    {
        Gate::authorize('view', $property);

        return new PropertyResource($this->properties->find($request->user(), $property));
    }

    /**
     * Add a property.
     *
     * Landlords only. Then add its units, and its charges in Billing settings.
     */
    public function store(StorePropertyRequest $request): JsonResponse
    {
        Gate::authorize('create', Property::class);

        PropertySubmitted::dispatch($request->toData());

        return response()->json(['message' => 'Property added.'], 202);
    }

    /**
     * Edit a property.
     */
    public function update(StorePropertyRequest $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);

        PropertySubmitted::dispatch($request->toData($property));

        return response()->json(['message' => 'Property updated.'], 202);
    }

    /**
     * List caretakers.
     *
     * Active staff of this account, for "who looks after this property". Needs "properties.manage".
     */
    public function caretakers(): JsonResponse
    {
        Gate::authorize('create', Property::class);

        return response()->json(['data' => $this->properties->caretakers()]);
    }
}
