<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('INSERT INTO tenants (id, name, customer_id, created_at, updated_at) SELECT id, name, id, created_at, created_at FROM customers ORDER BY id');

        DB::statement('UPDATE users SET tenant_id = customer_id WHERE customer_id IS NOT NULL');

        DB::statement("SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT MAX(id) FROM tenants))");
    }
}
