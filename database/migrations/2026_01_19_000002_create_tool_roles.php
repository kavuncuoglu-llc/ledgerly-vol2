<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/tool_roles.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM ledgerly_tool_read, ledgerly_tool_write;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM ledgerly_tool_read, ledgerly_tool_write;
        SQL);                                                                           // the roles stay: they are shared by every database on the server
    }
};
