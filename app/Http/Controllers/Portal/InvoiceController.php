<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Services\Billing\InvoicePdf;
use App\Services\Portal\TenantStatement;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Tenant portal', weight: 10)]
class InvoiceController extends Controller
{
    public function __construct(private TenantStatement $statement) {}

    /**
     * List my bills.
     *
     * The logged-in tenant's bills, newest first, 15 per page. Void bills are left out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection($this->statement->invoices($request->user()));
    }

    /**
     * Show one of my bills.
     *
     * With its lines (rent, metered usage with readings, fixed charges) and what's still owed on it.
     * Another tenant's bill, or a void one, is a 404.
     */
    public function show(Request $request, int $invoice): InvoiceResource
    {
        return new InvoiceResource($this->statement->invoice($request->user(), $invoice));
    }

    /**
     * Download one of my bills as a PDF.
     *
     * The same PDF that is emailed with the bill.
     */
    public function pdf(Request $request, int $invoice, InvoicePdf $pdf): Response
    {
        $bill = $this->statement->invoice($request->user(), $invoice)->load(['tenant', 'account']);

        return response($pdf->render($bill), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($bill).'"',
        ]);
    }
}
