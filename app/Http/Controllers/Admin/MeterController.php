<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\MetersSubmitted;
use App\Events\Commands\MeterSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FitMetersRequest;
use App\Http\Requests\Admin\StoreMeterRequest;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Properties\MeterService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Properties & units', weight: 4)]
class MeterController extends Controller
{
    /**
     * Fit a meter.
     *
     * One active meter per metered charge (e.g. electricity, water) per unit. The charge must exist as
     * a metered rate for the property.
     */
    public function store(StoreMeterRequest $request, Unit $unit): JsonResponse
    {
        Gate::authorize('update', $unit);

        MeterSubmitted::dispatch($request->toData($unit->id));

        return response()->json(['message' => 'Meter added.'], 202);
    }

    /**
     * Replace a meter.
     *
     * For a swapped or broken meter: the old one is deactivated (its readings stay on old bills) and
     * the new one starts from `initial_reading`.
     */
    public function update(StoreMeterRequest $request, Meter $meter): JsonResponse
    {
        Gate::authorize('update', $meter->unit);

        MeterSubmitted::dispatch($request->toData($meter->unit_id, $meter->charge_code, $meter->id));

        return response()->json(['message' => 'Meter replaced.'], 202);
    }

    /**
     * Meter setup for a property.
     *
     * Every unit with each metered charge of the property (e.g. electricity, water) and the meter fitted for
     * it, or null. `missing` counts units × charges still without a meter.
     */
    public function setup(Property $property, MeterService $meters): JsonResponse
    {
        Gate::authorize('view', $property);

        return response()->json(['data' => $meters->setup($property)]);
    }

    /**
     * Fit meters (bulk).
     *
     * Many meters on one property at once, all saved or none. Each needs today's reading: usage is
     * counted from there. A unit that already has a meter for the charge is refused (replace it instead).
     */
    public function fitMany(FitMetersRequest $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);

        MetersSubmitted::dispatch($property->id, $request->toData());

        return response()->json(['message' => 'Meters saved.'], 202);
    }
}
