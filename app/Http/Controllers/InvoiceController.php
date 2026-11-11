<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = InvoiceStatus::tryFrom((string) $request->query('status'));
        $withFees = $request->boolean('late_fees');

        $invoices = Invoice::query()
            ->with('customer')
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->when($withFees, fn ($query) => $query->withLateFee())
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('number', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'ilike', "%{$search}%"));
            }))
            ->orderByDesc('issued_at')
            ->paginate(config('ledgerly.per_page'))
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'search' => $search,
            'status' => $status,
            'withFees' => $withFees,
            'statuses' => InvoiceStatus::cases(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['customer', 'lineItems', 'payments']);

        return view('invoices.show', [
            'invoice' => $invoice,
        ]);
    }
}
