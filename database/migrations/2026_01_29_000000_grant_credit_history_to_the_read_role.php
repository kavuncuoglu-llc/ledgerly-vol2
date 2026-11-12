<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT SELECT (id, invoice_id, customer_id, amount_cents, created_at)
              ON invoice_credits TO ledgerly_tool_read;
        SQL);                                                                     // Chapter 51: check_goodwill_credit counts a customer's recent credits; it does not see why they were given
    }

    public function down(): void
    {
        DB::unprepared('REVOKE SELECT ON invoice_credits FROM ledgerly_tool_read;');
    }
};
