<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\DB;

trait ResetsSequences
{
    protected function resetSequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))");
    }
}
