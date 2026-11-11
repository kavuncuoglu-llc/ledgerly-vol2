<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $customers = Customer::query()
            ->withCount('invoices')
            ->withCount(['invoices as overdue_invoices_count' => fn ($query) => $query->where('status', 'overdue')])
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('contact_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(config('ledgerly.per_page'))
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->load('owner');

        return view('customers.show', [
            'customer' => $customer,
            'invoices' => $customer->invoices()->orderByDesc('issued_at')->paginate(config('ledgerly.per_page'))->withQueryString(),
            'tickets' => $customer->tickets()->orderByDesc('created_at')->limit(10)->get(),
            'users' => $customer->users()->orderBy('id')->get(),
        ]);
    }
}
