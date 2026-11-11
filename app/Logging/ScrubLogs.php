<?php

namespace App\Logging;

use Illuminate\Log\Logger;

// The `tap` every channel in config/logging.php names.
final class ScrubLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(app(ScrubPersonalData::class));
    }
}
