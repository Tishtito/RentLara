<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\UnitSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Properties\UnitService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Properties & units', weight: 4)]
class UnitController extends Controller
{
    /**
     * List a property's units.
     *
     * Each with its current lease (tenant, rent, next bill date and running `balance`) and active
     * meters. Vacant units have `current_lease: null`.
     */
    public function index(Property $property, UnitService $units): AnonymousResourceCollection
    {
        Gate::authorize('view', $property);

        return UnitResource::collection($units->forProperty($property));
    }

    /**
     * Add a unit.
     *
     * New units start vacant; give one a tenant with move-in.
     */
    public function store(StoreUnitRequest $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);

        UnitSubmitted::dispatch($request->toData($property->id));

        return response()->json(['message' => 'Unit added.'], 202);
    }

    /**
     * Edit a unit.
     *
     * Rent here is only the asking rent for the next move-in; a running lease keeps its own rent.
     * `utilities` switches a metered utility between the meter and a fixed monthly amount from the
     * tenant's next bill (today when vacant).
     */
    public function update(StoreUnitRequest $request, Unit $unit): JsonResponse
    {
        Gate::authorize('update', $unit);

        UnitSubmitted::dispatch($request->toData($unit->property_id, $unit));

        return response()->json(['message' => 'Unit updated.'], 202);
    }
}
