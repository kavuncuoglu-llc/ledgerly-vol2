<?php

namespace App\Ai\Doctor;

// What ai:doctor asks of one kind of model server: a header line, the checks in order, and the three-state health probe of Appendix L.
interface ServerChecks
{
    public function title(): string;

    /** @return list<Check> */
    public function run(): array;

    /** @return list<Check> reachable, loaded, warm; failures only when the server cannot be used at all */
    public function health(): array;
}
