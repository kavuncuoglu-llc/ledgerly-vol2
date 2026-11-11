<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Response;

// Chapter 33, Topic 6: the second yes, from the old address. The link is signed, so it cannot be built for another customer.
class BillingEmailConfirmationController extends Controller
{
    public function __invoke(Customer $customer): Response
    {
        abort_if($customer->billing_email_pending === null, 410, 'There is nothing to confirm.');

        $customer->forceFill(['email' => $customer->billing_email_pending, 'billing_email_pending' => null])->save();

        return response('Your billing email has been changed.');
    }
}
