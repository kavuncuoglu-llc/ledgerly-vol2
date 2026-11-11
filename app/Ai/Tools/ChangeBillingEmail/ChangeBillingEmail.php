<?php

namespace App\Ai\Tools\ChangeBillingEmail;

use App\Ai\Privacy\AcceptsVaultReferences;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Mail\BillingEmailChangeRequested;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

// The customer wrote the new address in the chat; the model only saw "<EMAIL_1>". This tool may be given the real one, from that conversation's vault (Chapter 34, Topic 5).
final class ChangeBillingEmail implements AcceptsVaultReferences, Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return ['new_email' => 'accounts@example.com'];
    }

    public function name(): string
    {
        return 'change_billing_email';
    }

    public function arguments(): string
    {
        return ChangeBillingEmailArgs::class;
    }

    public function description(): string
    {
        return 'Request a change of the current customer\'s billing email. It does not change anything until the customer confirms on an approval card and then confirms from the old address. Only call it when the customer has asked for the change and gave the new address.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteHighRisk;
    }

    public function perConversationLimit(): int
    {
        return 1;
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        $current = Customer::query()->select(['id', 'name', 'email'])->find($ctx->customerId());

        return sprintf('Change the billing email for %s from %s to %s. A confirmation is sent to the old address first.', $current?->name, $current?->email, strtolower(trim($args['new_email'])));
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $customer = Customer::query()->select(['id', 'email'])->findOrFail($ctx->customerId());

        DB::table('customers')->where('id', $customer->id)->update(['billing_email_pending' => $args->new_email]);        // the live address does not change yet

        $confirm = URL::temporarySignedRoute('billing-email.confirm', now()->addDay(), ['customer' => $customer->id]);

        Mail::to($customer->email)->queue(new BillingEmailChangeRequested($args->new_email, $confirm));          // to the OLD address

        return ToolResult::ok(['requested' => true, 'confirmation_sent_to' => 'the current billing email on file']);
    }
}
