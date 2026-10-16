<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\PaymentSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Payments', weight: 2)]
class PaymentController extends Controller
{
    /**
     * List payments.
     *
     * Newest first, 20 per page. Caretakers see payments for the properties they look after.
     */
    #[QueryParameter('lease_id', description: 'Only payments for this lease.', type: 'int', example: 1)]
    public function index(Request $request, PaymentService $payments): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payment::class);

        return PaymentResource::collection($payments->list($request->integer('lease_id') ?: null));
    }

    /**
     * Record a payment.
     *
     * Records money received against a lease (M-Pesa, bank, cash…). Without `invoice_id` the payment
     * is applied to the lease's latest bill. The payment is processed before the response, so
     * fetching the invoice afterwards shows its new `amount_paid` and status
     * (`partially_paid` / `paid`). `reference` is required for M-Pesa and bank payments and must be
     * unique per method, which blocks duplicate M-Pesa confirmations.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        Gate::authorize('create', [Payment::class, $request->lease()]);

        PaymentSubmitted::dispatch($request->toData());

        return response()->json(['message' => 'Payment accepted.'], 202);
    }
}
