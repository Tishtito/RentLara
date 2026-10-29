<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TenantPaymentResource;
use App\Services\Portal\TenantStatement;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Tenant portal', weight: 10)]
class HomeController extends Controller
{
    /**
     * Tenant home.
     *
     * The tenant's home(s) with this landlord: every current lease (usually one), or the last one after
     * moving out. Each has the running `balance` (positive = owes, negative = credit, the same figure as
     * the landlord sees) and the latest bill with its lines (null before the first bill). Plus the last
     * 5 payments.
     */
    public function __invoke(Request $request, TenantStatement $statement): JsonResponse
    {
        $tenant = $request->user();

        return response()->json([
            'homes' => $statement->homes($tenant)->map(fn (array $home) => [
                'lease_id' => $home['lease']->id,
                /** @example 7-7 */
                'unit_number' => $home['lease']->unit?->unit_number,
                /** @example Kims Apartments */
                'property_name' => $home['lease']->unit?->property?->name,
                'lease_status' => $home['lease']->status,
                'start_date' => $home['lease']->start_date?->toDateString(),
                'monthly_rent' => $home['lease']->monthly_rent,
                'next_billing_date' => $home['lease']->next_billing_date?->toDateString(),
                /** Running balance: positive = owes, negative = credit. */
                'balance' => $home['balance'],
                'latest_invoice' => $home['latest_invoice'] ? new InvoiceResource($home['latest_invoice']) : null,
            ])->values(),
            'recent_payments' => TenantPaymentResource::collection($statement->recentPayments($tenant)),
        ]);
    }
}
