<?php

namespace App\Facades;

use App\Ai\Registry\Registry as RegistryService;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ModelRegistryEntry add(string $model, array $options)
 * @method static ModelRegistryEntry find(string $name, int $version)
 * @method static string|null resolve(string $alias, ?string $default = null)
 * @method static ModelRegistryEntry|null productionFor(string $feature)
 * @method static Collection history(string $name)
 * @method static ModelRegistryEntry stage(string $name, int $version, string $to, string $by, ?int $percent = null, ?string $reason = null, ?string $evaluation = null)
 * @method static ModelRegistryEntry promote(string $name, int $version, string $by, bool $force = false, ?string $reason = null)
 * @method static ModelRegistryEntry rollback(string $name, string $reason, string $by, ?int $to = null)
 * @method static ModelRegistryEntry|null stopTrial(string $name, string $reason, string $by)
 * @method static Collection retireExpired(string $by = 'registry:retire')
 * @method static void publish()
 *
 * @see RegistryService
 */
final class Registry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RegistryService::class;
    }
}
