<?php

namespace Tests;

use App\Ai\Security\Untrusted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'ledgerly_testing') {
            throw new RuntimeException("Refusing to run tests against [{$database}]. Tests must use ledgerly_testing.");
        }

        DB::statement('SET LOCAL enable_indexscan = off');   // an HNSW index keeps the entries of every rolled-back test and starts missing rows in a long run; at this table size a scan is exact and as fast

        Untrusted::reset('b1f3ec6d');   // the wrapper's tag is random per request; a test needs the same bytes every time

        Http::preventStrayRequests();   // no test may reach a model server, the sidecar or the internet
    }
}
