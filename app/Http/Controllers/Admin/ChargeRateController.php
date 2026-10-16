<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\ChargeRateChangeSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeChargeRateRequest;
use App\Http\Resources\ChargeRateResource;
use App\Models\ChargeRate;
use App\Models\Property;
use App\Services\Rates\ChargeRateService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Billing settings', weight: 6)]
class ChargeRateController extends Controller
{
    /**
     * List a property's charges.
     *
     * Every version of every price, property-wide and per-unit overrides, newest first per charge. The
     * current price of a charge has `effective_to: null`. Old versions are kept so past bills match.
     */
    public function index(Property $property, ChargeRateService $rates): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ChargeRate::class, $property]);

        return ChargeRateResource::collection($rates->history($property));
    }

    /**
     * Add a charge or change a price.
     *
     * Prices are never edited: this closes the current version the day before `effective_from` and
     * starts a new one, so bills before that date keep the old price. A new `charge_code` adds a charge
     * (then `label` and `billing_type` are required). Send `unit_id` to set a price for one unit only.
     * Landlords only.
     */
    public function store(ChangeChargeRateRequest $request, Property $property): JsonResponse
    {
        Gate::authorize('change', [ChargeRate::class, $property]);

        ChargeRateChangeSubmitted::dispatch($request->toData($property));

        return response()->json(['message' => 'Price saved.'], 202);
    }
}
