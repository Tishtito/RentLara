<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantPaymentResource;
use App\Services\Portal\TenantStatement;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tenant portal', weight: 10)]
class PaymentController extends Controller
{
    /**
     * List my payments.
     *
     * The logged-in tenant's confirmed payments, newest first, 20 per page.
     */
    public function index(Request $request, TenantStatement $statement): AnonymousResourceCollection
    {
        return TenantPaymentResource::collection($statement->payments($request->user()));
    }
}
