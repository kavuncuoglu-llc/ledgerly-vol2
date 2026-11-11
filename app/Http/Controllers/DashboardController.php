<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\TicketStatus;
use App\Help\HelpArticle;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Support\Ledgerly;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $statusCounts = Invoice::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard', [
            'today' => Ledgerly::today(),
            'customerCount' => Customer::count(),
            'invoiceCount' => $statusCounts->sum(),
            'statusCounts' => collect(InvoiceStatus::cases())->mapWithKeys(
                fn (InvoiceStatus $status) => [$status->value => (int) ($statusCounts[$status->value] ?? 0)]
            ),
            'lateFeeCount' => Invoice::withLateFee()->count(),
            'openTicketCount' => Ticket::where('status', '!=', TicketStatus::Solved->value)->count(),
            'articleCount' => HelpArticle::all()->count(),
            'overdueInvoices' => Invoice::with('customer')
                ->where('status', InvoiceStatus::Overdue->value)
                ->orderBy('due_at')
                ->limit(8)
                ->get(),
        ]);
    }
}
