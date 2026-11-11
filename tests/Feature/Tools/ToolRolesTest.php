<?php

use Illuminate\Support\Facades\DB;

// Chapter 33, Topic 5: the grants are the policy, so they are tested as data.
function canSelect(string $role, string $table, string $column): bool
{
    return DB::selectOne('select has_column_privilege(?, ?, ?, ?) as ok', [$role, $table, $column, 'SELECT'])->ok;
}

function canUpdate(string $role, string $table, string $column): bool
{
    return DB::selectOne('select has_column_privilege(?, ?, ?, ?) as ok', [$role, $table, $column, 'UPDATE'])->ok;
}

it('creates two roles that cannot log in', function () {
    $roles = DB::table('pg_roles')->whereIn('rolname', ['ledgerly_tool_read', 'ledgerly_tool_write'])->get(['rolname', 'rolcanlogin', 'rolsuper', 'rolbypassrls']);

    expect($roles)->toHaveCount(2)
        ->and($roles->every(fn ($role) => ! $role->rolcanlogin && ! $role->rolsuper && ! $role->rolbypassrls))->toBeTrue();
})->group('tools');

it('lets the read role select the invoice columns the tools show, and not the notes', function () {
    foreach (['number', 'status', 'total', 'issued_at', 'due_at', 'late_fee_cents', 'customer_id'] as $column) {
        expect(canSelect('ledgerly_tool_read', 'invoices', $column))->toBeTrue("read role cannot select invoices.{$column}");
    }

    expect(canSelect('ledgerly_tool_read', 'invoices', 'notes'))->toBeFalse()
        ->and(canSelect('ledgerly_tool_read', 'customers', 'email'))->toBeFalse()
        ->and(canSelect('ledgerly_tool_read', 'customers', 'contact_name'))->toBeFalse()
        ->and(canSelect('ledgerly_tool_read', 'users', 'email'))->toBeFalse()
        ->and(canSelect('ledgerly_tool_read', 'users', 'password'))->toBeFalse();
})->group('tools');

it('lets the read role write nothing at all', function () {
    foreach (['invoices', 'customers', 'tickets', 'payments', 'invoice_credits'] as $table) {
        expect(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['ledgerly_tool_read', $table, 'INSERT, UPDATE, DELETE'])->ok)->toBeFalse("read role can write {$table}");
    }
})->group('tools');

it('lets the write role update one customer column and nothing else of an invoice', function () {
    expect(canUpdate('ledgerly_tool_write', 'customers', 'billing_email_pending'))->toBeTrue()
        ->and(canUpdate('ledgerly_tool_write', 'customers', 'email'))->toBeFalse()
        ->and(canUpdate('ledgerly_tool_write', 'invoices', 'total'))->toBeFalse()
        ->and(canUpdate('ledgerly_tool_write', 'invoices', 'status'))->toBeFalse()
        ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['ledgerly_tool_write', 'tickets', 'INSERT'])->ok)->toBeTrue()
        ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['ledgerly_tool_write', 'tickets', 'DELETE'])->ok)->toBeFalse();
})->group('tools');

it('keeps the tool roles away from the audit and approval tables', function () {
    foreach (['tool_calls', 'pending_tool_actions', 'model_calls', 'messages'] as $table) {
        expect(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['ledgerly_tool_write', $table, 'SELECT'])->ok)->toBeFalse("{$table} is readable by a tool")
            ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['ledgerly_tool_read', $table, 'SELECT'])->ok)->toBeFalse();
    }
})->group('tools');
