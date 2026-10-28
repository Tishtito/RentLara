<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantPaymentResource;
use App\Services\Payments\ReceiptPdf;
use App\Services\Portal\TenantStatement;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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

    /**
     * Download my payment receipt.
     *
     * The same PDF that was sent when the payment was confirmed. Another tenant's payment, or one
     * without a receipt, is a 404.
     */
    public function receipt(Request $request, int $payment, TenantStatement $statement, ReceiptPdf $pdf): Response
    {
        $receipt = $statement->receipt($request->user(), $payment);

        return response($pdf->render($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($receipt).'"',
        ]);
    }
}
