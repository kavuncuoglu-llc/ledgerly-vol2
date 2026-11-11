-- Chapter 33, Topic 5: two NOLOGIN roles the tool gate switches into with SET LOCAL ROLE, for the length of one transaction.
-- Column grants on the base tables, so row-level security still applies to the tool role.
-- (A plain view runs with its owner's rights and can bypass RLS; if you use views, create them WITH (security_invoker = true), available from PostgreSQL 15.)
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'ledgerly_tool_read') THEN
        CREATE ROLE ledgerly_tool_read NOLOGIN;
    END IF;

    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'ledgerly_tool_write') THEN
        CREATE ROLE ledgerly_tool_write NOLOGIN;
    END IF;
END
$$;

-- The app's own database user can switch into them (a superuser always can).
GRANT ledgerly_tool_read, ledgerly_tool_write TO CURRENT_USER;

-- Read tools: the columns the tools show the model, and no others (no invoice notes, no contact name or email).
GRANT SELECT (id, customer_id, number, status, currency, subtotal, tax, total, issued_at, due_at, paid_at, late_fee_cents, created_at, updated_at, deleted_at)
  ON invoices TO ledgerly_tool_read;
GRANT SELECT (id, invoice_id, description, quantity, unit_price, amount, position)
  ON invoice_line_items TO ledgerly_tool_read;
GRANT SELECT (id, invoice_id, amount, method, reference, paid_at)
  ON payments TO ledgerly_tool_read;
GRANT SELECT (id, name, plan, status, currency, country, timezone, seats)
  ON customers TO ledgerly_tool_read;
GRANT SELECT (id, customer_id, role)
  ON users TO ledgerly_tool_read;
GRANT SELECT ON help_chunks TO ledgerly_tool_read;

-- Write tools: the few things a write tool may change, and what it must read to do it.
GRANT SELECT (id, customer_id, number, status, currency, subtotal, tax, total, issued_at, due_at, paid_at, late_fee_cents, created_at, updated_at, deleted_at)
  ON invoices TO ledgerly_tool_write;
GRANT SELECT (id, email, billing_email_pending)
  ON customers TO ledgerly_tool_write;
GRANT UPDATE (billing_email_pending)
  ON customers TO ledgerly_tool_write;
GRANT SELECT, INSERT ON tickets TO ledgerly_tool_write;
GRANT USAGE ON SEQUENCE tickets_id_seq TO ledgerly_tool_write;
GRANT SELECT, INSERT ON payment_retries TO ledgerly_tool_write;
GRANT USAGE ON SEQUENCE payment_retries_id_seq TO ledgerly_tool_write;
GRANT SELECT, INSERT ON invoice_credits TO ledgerly_tool_write;
GRANT USAGE ON SEQUENCE invoice_credits_id_seq TO ledgerly_tool_write;
