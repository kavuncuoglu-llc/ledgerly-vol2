<?php

namespace App\Ai\Cost;

use RuntimeException;

final class UnpricedModel extends RuntimeException
{
    public static function for(string $provider, string $model): self
    {
        return new self("No price for model [{$model}] on provider [{$provider}]: add it to config/ai-pricing.php. An unpriced model is never counted as free.");
    }
}
